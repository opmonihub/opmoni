<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpContactAttachment;
use App\Models\Erp\ErpContactNote;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ErpContactNotesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $attachmentTestRoots = [];

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $filesystem = new Filesystem;
            foreach ($this->attachmentTestRoots as $root) {
                $filesystem->deleteDirectory($root);
            }
        }
    }

    private function usePrivateAttachmentTestDisk(): FilesystemAdapter
    {
        $root = sys_get_temp_dir().'/opmoni-task25-storage-'.getmypid().'-'.bin2hex(random_bytes(12));
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new \RuntimeException('Unable to create the private Task25 test storage root.');
        }
        $this->attachmentTestRoots[] = $root;
        $disk = Storage::build([
            'driver' => 'local',
            'root' => $root,
            'serve' => false,
            'throw' => false,
            'report' => false,
        ]);
        Storage::set('erp-contact-attachments', $disk);

        return $disk;
    }

    public function test_contact_note_index_returns_a_bounded_visible_projection(): void
    {
        $context = $this->ownerContext();

        $this->withHeaders($context['headers'])
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'state' => 'active', 'total' => 0]]);
    }

    public function test_updating_note_returns_its_active_attachment_count(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Anotação com arquivo']);
        $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('anexo.pdf', "%PDF-1.4\nanexo\n"),
            ])
            ->assertCreated();

        $this->withHeaders($context['headers'])
            ->patchJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}", ['heading' => 'Título atualizado'])
            ->assertOk()
            ->assertJsonPath('data.heading', 'Título atualizado')
            ->assertJsonPath('data.attachments_count', 1);
    }

    public function test_note_validation_uses_bounded_portuguese_field_errors(): void
    {
        $context = $this->ownerContext();

        $this->withHeaders($context['headers'])
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", ['heading' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.heading.0', 'O título deve ter no máximo 255 caracteres.');

        $this->withHeaders($context['headers'])
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", ['description' => str_repeat('a', 10001)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.description.0', 'A anotação deve ter no máximo 10.000 caracteres.');
        $note = $this->createNote($context, ['heading' => 'Obrigatoriedade do arquivo']);
        $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Selecione um arquivo para anexar.');
    }

    public function test_note_persistence_failures_return_safe_503_without_reporting_private_bindings(): void
    {
        $context = $this->ownerContext();
        $connection = DB::connection();
        $failNextNoteWrite = true;
        $privateSentinel = 'private-note-sentinel-should-not-leak';
        $failedStatements = [];
        $connection->beforeExecuting(function (string $query, array $bindings, $connection) use (&$failNextNoteWrite, &$failedStatements, $privateSentinel): void {
            $normalizedQuery = strtolower(ltrim($query));
            $statement = match (true) {
                str_starts_with($normalizedQuery, 'insert into "erp_contact_notes"') => 'insert',
                str_starts_with($normalizedQuery, 'update "erp_contact_notes"') => 'update',
                default => null,
            };
            if (! $failNextNoteWrite || $statement === null) {
                return;
            }

            $failNextNoteWrite = false;
            $failedStatements[] = $statement;
            throw new QueryException($connection->getName(), $query, $bindings, new \PDOException($privateSentinel));
        });
        Log::spy();

        $this->withHeaders($context['headers'])
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", [
                'heading' => $privateSentinel,
                'is_private' => true,
            ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'contact_note_unavailable')
            ->assertJsonPath('message', 'Não foi possível salvar a anotação ou o arquivo. Tente novamente mais tarde.')
            ->assertDontSee($privateSentinel);
        $this->assertSame(['insert'], $failedStatements);
        $this->assertSame(0, ErpContactNote::query()->count());

        $note = $this->createNote($context, ['heading' => 'Valor preservado']);
        $failNextNoteWrite = true;
        $this->withHeaders($context['headers'])
            ->patchJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}", [
                'description' => $privateSentinel,
                'is_private' => true,
            ])
            ->assertStatus(503)
            ->assertDontSee($privateSentinel);
        $this->assertSame(['insert', 'update'], $failedStatements);
        $this->assertSame('Valor preservado', ErpContactNote::query()->findOrFail($note['id'])->heading);
        $this->assertNull(ErpContactNote::query()->findOrFail($note['id'])->description);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    public function test_private_notes_and_pending_counts_are_hidden_from_company_wildcard_users(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $private = $this->createNote($context, ['heading' => 'Restrita', 'is_private' => true]);
        $privateAttachment = $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$private['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('restrito.pdf', "%PDF-1.4\nprivado\n"),
            ])
            ->assertCreated()
            ->json('data');
        $shared = $this->createNote($context, ['heading' => 'Compartilhada']);
        ErpContactAttachment::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_contact_note_id' => $private['id'],
            'status' => 'deletion_pending',
        ]);
        ErpContactAttachment::factory()->create([
            'erp_company_id' => $context['company']->id,
            'erp_contact_note_id' => $shared['id'],
            'status' => 'unavailable',
        ]);
        $manager = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $context['company']->id,
            'user_id' => $manager->id,
            'role' => 'manager',
        ]);
        $secondOwner = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $context['company']->id,
            'user_id' => $secondOwner->id,
            'role' => 'owner',
        ]);
        $managerHeaders = $this->loginAndSelect($manager, $context['company']);

        $this->withHeaders($managerHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shared['id'])
            ->assertJsonPath('data.0.attachments_count', 1)
            ->assertJsonPath('data.0.can_change_privacy', false);
        $this->withHeaders($managerHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$private['id']}")
            ->assertNotFound();
        $this->withHeaders($managerHeaders)
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$private['id']}/attachments/{$privateAttachment['id']}/download")
            ->assertNotFound();
        $ownerHeaders = $this->loginAndSelect($secondOwner, $context['company']);
        $this->withHeaders($ownerHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shared['id']);
        $this->withHeaders($ownerHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$private['id']}")
            ->assertNotFound();
        $this->withHeaders($ownerHeaders)
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$private['id']}/attachments/{$privateAttachment['id']}/download")
            ->assertNotFound();
        $this->withHeaders($managerHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes?state=deletion_pending")
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);

        $authorHeaders = $this->loginAndSelect($context['user'], $context['company']);
        $this->withHeaders($authorHeaders)
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$private['id']}/attachments/{$privateAttachment['id']}/download")
            ->assertOk()
            ->assertSee('%PDF-1.4', false);
        $this->withHeaders($authorHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes?state=deletion_pending")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $private['id'])
            ->assertJsonPath('data.0.attachments_count', 1);
    }

    public function test_foreign_contact_and_revoked_membership_are_denied_before_attachment_bytes(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Bytes protegidos']);
        $upload = $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('protegido.pdf', "%PDF-1.4\nprotegido\n"),
            ])
            ->assertCreated()
            ->json('data');
        $foreignCompany = ErpCompany::factory()->create();
        $foreignContact = ErpContact::factory()->create(['erp_company_id' => $foreignCompany->id]);

        $this->withHeaders($context['headers'])
            ->get("/api/v1/erp/contacts/{$foreignContact->id}/notes/{$note['id']}/attachments/{$upload['id']}/download")
            ->assertNotFound();

        ErpCompanyMembership::query()
            ->where('erp_company_id', $context['company']->id)
            ->where('user_id', $context['user']->id)
            ->delete();
        $this->withHeaders($context['headers'])
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$upload['id']}/download")
            ->assertForbidden();
    }

    public function test_shared_note_author_controls_privacy_transitions_and_forged_authorship_is_rejected(): void
    {
        $context = $this->ownerContext();
        $this->withHeaders($context['headers'])
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", [
                'heading' => 'Autoria imutável',
                'created_by' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('created_by');
        $shared = $this->createNote($context, ['heading' => 'Compartilhada']);
        $manager = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $context['company']->id,
            'user_id' => $manager->id,
            'role' => 'manager',
        ]);
        $managerHeaders = $this->loginAndSelect($manager, $context['company']);

        $this->withHeaders($managerHeaders)
            ->patchJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$shared['id']}", ['is_private' => true])
            ->assertForbidden();
        $authorHeaders = $this->loginAndSelect($context['user'], $context['company']);
        $this->withHeaders($authorHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.can_change_privacy', true);
        $this->withHeaders($authorHeaders)
            ->patchJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$shared['id']}", ['is_private' => true])
            ->assertOk()
            ->assertJsonPath('data.is_private', true);
        $managerHeaders = $this->loginAndSelect($manager, $context['company']);
        $this->withHeaders($managerHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$shared['id']}")
            ->assertNotFound();
        $authorHeaders = $this->loginAndSelect($context['user'], $context['company']);
        $this->withHeaders($authorHeaders)
            ->patchJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$shared['id']}", ['is_private' => false])
            ->assertOk()
            ->assertJsonPath('data.is_private', false);
        $managerHeaders = $this->loginAndSelect($manager, $context['company']);
        $this->withHeaders($managerHeaders)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$shared['id']}")
            ->assertOk()
            ->assertJsonPath('data.can_change_privacy', false);
    }

    public function test_viewer_can_read_notes_but_cannot_write_them(): void
    {
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Somente leitura']);
        $viewer = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $context['company']->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
        ]);
        $headers = $this->loginAndSelect($viewer, $context['company']);

        $this->withHeaders($headers)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->withHeaders($headers)
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", ['heading' => 'Negada'])
            ->assertForbidden();
        $this->withHeaders($headers)
            ->patchJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}", ['heading' => 'Negada'])
            ->assertForbidden();
    }

    public function test_explicit_delegated_permission_subset_applies_to_note_routes(): void
    {
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Permissão limitada']);
        $limited = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $context['company']->id,
            'user_id' => $limited->id,
            'role' => 'manager',
            'action_permissions' => ['erp.contacts.notes.index'],
        ]);
        $headers = $this->loginAndSelect($limited, $context['company']);

        $this->withHeaders($headers)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.can_change_privacy', false);
        $this->withHeaders($headers)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}")
            ->assertForbidden();
        $this->withHeaders($headers)
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", ['heading' => 'Negada'])
            ->assertForbidden();
    }

    public function test_office_delegation_grants_only_the_exact_note_actions(): void
    {
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Escopo delegado']);
        $office = Account::factory()->create();
        $delegate = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $office->id,
            'user_id' => $delegate->id,
            'role' => 'user',
        ]);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $context['company']->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $context['user']->id,
            'permission_allowlist' => ['erp.contacts.notes.index'],
        ]);
        $headers = $this->loginAndSelectDelegated($delegate, $office, $context['company']);

        $this->withHeaders($headers)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->withHeaders($headers)
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}")
            ->assertForbidden();
        $this->withHeaders($headers)
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", ['heading' => 'Negada'])
            ->assertForbidden();
    }

    public function test_single_column_csv_accepts_one_megabyte_and_rejects_one_byte_more(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Anexo grande']);
        $oneMegabyte = str_repeat("x\n", 500_000);
        $uploadPath = "/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments";

        $accepted = $this->withHeaders($context['headers'])
            ->post($uploadPath, ['file' => UploadedFile::fake()->createWithContent('limite.csv', $oneMegabyte)])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1_000_000, $accepted['byte_length']);
        $this->assertSame('text/plain', $accepted['mime_type']);
        $attachment = ErpContactAttachment::query()->findOrFail($accepted['id']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $attachment->storage_key);
        $this->assertStringNotContainsString($attachment->storage_key, json_encode($accepted, JSON_THROW_ON_ERROR));

        $download = $this->withHeaders($context['headers'])
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$accepted['id']}/download")
            ->assertOk()
            ->assertHeader('Content-Length', '1000000')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename=limite.csv');
        $this->assertSame(strlen($oneMegabyte), strlen($download->getContent()));
        $this->assertSame(hash('sha256', $oneMegabyte), hash('sha256', $download->getContent()));

        $this->withHeaders($context['headers'])
            ->post($uploadPath, ['file' => UploadedFile::fake()->createWithContent('excedente.csv', $oneMegabyte.'x')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_all_approved_file_formats_have_positive_server_detected_mime_fixtures(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Formatos aprovados']);
        $docxBytes = $this->zipBytes(['word/document.xml' => '<document/>']);
        $zipBytes = $this->zipBytes(['payload.bin' => "opaque\0bytes"]);
        $docBytes = file_get_contents(base_path('tests/Fixtures/ErpContactNotes/apache-empty.doc'));
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true);
        $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9k=', true);
        $this->assertIsString($docBytes);
        $this->assertIsString($pngBytes);
        $this->assertIsString($jpegBytes);
        $formats = [
            ['documento.pdf', "%PDF-1.4\nconteúdo\n", 'application/pdf'],
            ['dados.csv', "name,value\nItem,10\n", 'text/csv'],
            ['compactado.zip', $zipBytes, 'application/zip'],
            ['documento.doc', $docBytes, 'application/msword'],
            ['documento.docx', $docxBytes, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['imagem.jpg', $jpegBytes, 'image/jpeg'],
            ['imagem.png', $pngBytes, 'image/png'],
        ];

        foreach ($formats as [$name, $bytes, $mime]) {
            $upload = $this->withHeaders($context['headers'])
                ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                    'file' => UploadedFile::fake()->createWithContent($name, $bytes),
                ])
                ->assertCreated()
                ->assertJsonPath('data.mime_type', $mime)
                ->json('data');
            $download = $this->withHeaders($context['headers'])
                ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$upload['id']}/download")
                ->assertOk();
            $this->assertSame(strlen($bytes), strlen($download->getContent()));
            $this->assertSame(hash('sha256', $bytes), hash('sha256', $download->getContent()));
        }
    }

    public function test_pfx_octet_stream_zip_renamed_docx_and_unsafe_names_are_rejected(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Validação de anexos']);
        $path = "/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments";
        $makeZip = function (string $entry): string {
            $zipPath = tempnam(sys_get_temp_dir(), 'erp-contact-zip-');
            $archive = new \ZipArchive;
            $this->assertSame(true, $archive->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            $archive->addFromString($entry, '<document/>');
            $archive->close();
            $bytes = file_get_contents($zipPath);
            @unlink($zipPath);
            $this->assertIsString($bytes);

            return $bytes;
        };
        $docxBytes = $makeZip('word/document.xml');
        $zipBytes = $makeZip('payload.bin');

        foreach ([
            ['certificado.pfx', 'not-a-real-pfx'],
            ['binario.bin', "\x00\x01\x02\x03\x04"],
            ['arquivo.docx', $zipBytes],
            ['documento-renomeado.zip', $docxBytes],
            ['../caminho.pdf', "%PDF-1.4\nconteúdo\n"],
            ["nome\r\nX-Evil: yes.pdf", "%PDF-1.4\nconteúdo\n"],
        ] as [$name, $contents]) {
            $response = $this->withHeaders($context['headers'])
                ->post($path, ['file' => UploadedFile::fake()->createWithContent($name, $contents)]);
            $this->assertSame(422, $response->getStatusCode(), $name.': '.$response->getContent());
            $response->assertJsonValidationErrors('file');
        }
        $this->assertSame(0, ErpContactAttachment::query()->count());
    }

    public function test_corrupt_oversized_stored_file_becomes_unavailable_and_returns_retryable_503(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Arquivo corrompido']);
        $upload = $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('corrompido.pdf', "%PDF-1.4\nconteúdo\n"),
            ])
            ->assertCreated()
            ->json('data');
        $attachment = ErpContactAttachment::query()->findOrFail($upload['id']);
        Storage::disk('erp-contact-attachments')->put($attachment->storage_key, str_repeat('x', 1_000_001));

        $this->withHeaders($context['headers'])
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$attachment->id}/download")
            ->assertStatus(503)
            ->assertJsonPath('code', 'attachment_unavailable')
            ->assertJsonPath('message', 'O arquivo não está disponível ou não passou na verificação de integridade. Tente novamente mais tarde.');
        $this->assertSame('unavailable', $attachment->fresh()->status);
    }

    public function test_storage_put_and_readback_failures_create_safe_unavailable_metadata(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $filesystem = app('filesystem');
        $disk = Storage::disk('erp-contact-attachments');
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Armazenamento indisponível']);
        $path = "/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments";
        $putFailureDisk = \Mockery::mock($disk)->makePartial();
        $putFailureDisk->shouldReceive('put')->once()->andReturn(false);
        Storage::partialMock()->shouldReceive('disk')->with('erp-contact-attachments')->andReturn($putFailureDisk);

        $this->withHeaders($context['headers'])
            ->post($path, ['file' => UploadedFile::fake()->createWithContent('put-falhou.pdf', "%PDF-1.4\nput\n")])
            ->assertStatus(503)
            ->assertJsonPath('code', 'attachment_unavailable')
            ->assertJsonPath('data.status', 'unavailable');
        $this->assertSame('unavailable', ErpContactAttachment::query()->latest('id')->firstOrFail()->status);

        Storage::swap($filesystem);
        $disk = Storage::disk('erp-contact-attachments');
        $readbackFailureDisk = \Mockery::mock($disk)->makePartial();
        $readbackFailureDisk->shouldReceive('readStream')->once()->andReturn(false);
        Storage::partialMock()->shouldReceive('disk')->with('erp-contact-attachments')->andReturn($readbackFailureDisk);

        $this->withHeaders($context['headers'])
            ->post($path, ['file' => UploadedFile::fake()->createWithContent('leitura-falhou.pdf', "%PDF-1.4\nread\n")])
            ->assertStatus(503)
            ->assertJsonPath('code', 'attachment_unavailable')
            ->assertJsonPath('data.status', 'unavailable');
        $this->assertSame(2, ErpContactAttachment::query()->count());
        $this->assertSame(2, ErpContactAttachment::query()->where('status', 'unavailable')->count());
        Storage::swap($filesystem);

        $disk = Storage::disk('erp-contact-attachments');
        $sentinelKey = str_repeat('a', 64);
        $sentinelBytes = "%PDF-1.4\nsentinel\n";
        $this->assertTrue($disk->put($sentinelKey, $sentinelBytes));
        $uploadedBytes = "%PDF-1.4\nhash-source\n";
        $mismatchedBytes = "%PDF-1.4\nhash-target\n";
        $this->assertSame(strlen($uploadedBytes), strlen($mismatchedBytes));
        $this->assertNotSame(hash('sha256', $uploadedBytes), hash('sha256', $mismatchedBytes));

        $readbackKey = null;
        $stagedBytesBeforeReadback = null;
        $hashMismatchDisk = \Mockery::mock($disk)->makePartial();
        $hashMismatchDisk->shouldReceive('readStream')->once()->andReturnUsing(function (string $key) use (&$readbackKey, &$stagedBytesBeforeReadback, $disk, $mismatchedBytes) {
            $readbackKey = $key;
            $stagedBytesBeforeReadback = $disk->get($key);
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $mismatchedBytes);
            rewind($stream);

            return $stream;
        });
        Storage::partialMock()->shouldReceive('disk')->with('erp-contact-attachments')->andReturn($hashMismatchDisk);

        $this->withHeaders($context['headers'])
            ->post($path, ['file' => UploadedFile::fake()->createWithContent('hash-mismatch.pdf', $uploadedBytes)])
            ->assertStatus(503)
            ->assertJsonPath('code', 'attachment_unavailable')
            ->assertJsonPath('data.status', 'unavailable');

        Storage::swap($filesystem);
        $mismatchedAttachment = ErpContactAttachment::query()->latest('id')->firstOrFail();
        $this->assertSame('unavailable', $mismatchedAttachment->status);
        $this->assertSame($readbackKey, $mismatchedAttachment->storage_key);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $mismatchedAttachment->storage_key);
        $this->assertNotSame($sentinelKey, $mismatchedAttachment->storage_key);
        $this->assertSame($uploadedBytes, $stagedBytesBeforeReadback);
        $this->assertFalse($disk->exists($mismatchedAttachment->storage_key));
        $this->assertTrue($disk->exists($sentinelKey));
        $this->assertSame($sentinelBytes, $disk->get($sentinelKey));
    }

    public function test_failed_physical_attachment_removal_stays_visible_and_can_be_retried(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $disk = Storage::disk('erp-contact-attachments');
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Remoção recuperável']);
        $upload = $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('retry.pdf', "%PDF-1.4\nretry\n"),
            ])
            ->assertCreated()
            ->json('data');
        $attachment = ErpContactAttachment::query()->findOrFail($upload['id']);
        $nativeFilesystem = app(Filesystem::class);
        $nativeFilesystemMock = \Mockery::mock($nativeFilesystem)->makePartial();
        $nativeFilesystemMock->shouldReceive('delete')->once()->with($disk->path($attachment->storage_key))->andReturn(false);
        app()->instance(Filesystem::class, $nativeFilesystemMock);

        try {
            $this->withHeaders($context['headers'])
                ->deleteJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$attachment->id}")
                ->assertAccepted()
                ->assertJsonPath('data.status', 'deletion_pending');
        } finally {
            app()->instance(Filesystem::class, $nativeFilesystem);
        }
        $this->assertSame('deletion_pending', $attachment->fresh()->status);
        $this->assertTrue($disk->exists($attachment->storage_key));

        $this->withHeaders($context['headers'])
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes?state=deletion_pending")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $note['id'])
            ->assertJsonPath('data.0.attachments_count', 1);
        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $context['headers']['X-CSRF-Token']])
            ->deleteJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$attachment->id}")
            ->assertAccepted();
        $this->assertNull($attachment->fresh());
        $this->assertFalse(Storage::disk('erp-contact-attachments')->exists($attachment->storage_key));
    }

    public function test_ambiguous_disk_absence_never_discards_pending_attachment_metadata(): void
    {
        $disk = $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Ausência ambígua']);
        $upload = $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('ambiguous.pdf', "%PDF-1.4\nambiguous\n"),
            ])
            ->assertCreated()
            ->json('data');
        $attachment = ErpContactAttachment::query()->findOrFail($upload['id']);
        $storageKey = $attachment->storage_key;
        $storedBytes = $disk->get($storageKey);
        $sentinelKey = str_repeat('b', 64);
        $sentinelBytes = "%PDF-1.4\nunrelated sentinel\n";
        $this->assertTrue($disk->put($sentinelKey, $sentinelBytes));

        $storageManager = app('filesystem');
        $nativeFilesystem = app(Filesystem::class);
        $diskMock = \Mockery::mock($disk)->makePartial();
        $diskMock->shouldReceive('exists')->with($storageKey)->zeroOrMoreTimes()->andReturn(false);
        $nativeFilesystemMock = \Mockery::mock($nativeFilesystem)->makePartial();
        $nativeFilesystemMock->shouldReceive('delete')->once()->with($disk->path($storageKey))->andReturn(false);
        Storage::shouldReceive('disk')->with('erp-contact-attachments')->andReturn($diskMock);
        app()->instance(Filesystem::class, $nativeFilesystemMock);

        try {
            $this->withHeaders($context['headers'])
                ->deleteJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$attachment->id}")
                ->assertAccepted()
                ->assertJsonPath('data.status', 'deletion_pending');
        } finally {
            Storage::swap($storageManager);
            app()->instance(Filesystem::class, $nativeFilesystem);
        }

        $pending = ErpContactAttachment::query()->find($attachment->id);
        $this->assertNotNull($pending, 'ambiguous adapter absence must retain retryable metadata');
        $this->assertSame('deletion_pending', $pending->status);
        $this->assertTrue($disk->exists($storageKey));
        $this->assertSame($storedBytes, $disk->get($storageKey));
        $this->assertTrue($disk->exists($sentinelKey));
        $this->assertSame($sentinelBytes, $disk->get($sentinelKey));

        $this->withHeaders($context['headers'])
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes?state=deletion_pending")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $note['id'])
            ->assertJsonPath('data.0.attachments_count', 1);

        $this->withHeaders($context['headers'])
            ->deleteJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$attachment->id}")
            ->assertAccepted();
        $this->assertNull(ErpContactAttachment::query()->find($attachment->id));
        $this->assertFalse($disk->exists($storageKey));
        $this->assertTrue($disk->exists($sentinelKey));
        $this->assertSame($sentinelBytes, $disk->get($sentinelKey));
    }

    public function test_archived_contact_keeps_note_and_download_history_but_rejects_new_content(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Histórico do contato']);
        $path = "/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments";
        $upload = $this->withHeaders($context['headers'])
            ->post($path, ['file' => UploadedFile::fake()->createWithContent('historico.pdf', "%PDF-1.4\nconteúdo\n")])
            ->assertCreated()
            ->json('data');

        $this->withHeaders([...$context['headers'], 'X-CSRF-Token' => $context['headers']['X-CSRF-Token']])
            ->deleteJson("/api/v1/erp/contacts/{$context['contact']->id}")
            ->assertOk();

        $this->withHeaders($context['headers'])
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $note['id']);
        $this->withHeaders($context['headers'])
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$upload['id']}/download")
            ->assertOk()
            ->assertSee('%PDF-1.4', false);
        $this->withHeaders($context['headers'])
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", ['heading' => 'Nova'])
            ->assertNotFound();
        $this->withHeaders($context['headers'])
            ->post($path, ['file' => UploadedFile::fake()->createWithContent('novo.pdf', "%PDF-1.4\n")])
            ->assertNotFound();
    }

    public function test_note_deletion_pending_blocks_attachment_download_and_is_visible_for_retry(): void
    {
        $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Remoção pendente']);
        $upload = $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('pendente.pdf', "%PDF-1.4\nconteúdo\n"),
            ])
            ->assertCreated()
            ->json('data');

        ErpContactNote::query()->whereKey($note['id'])->update(['state' => 'deletion_pending']);
        ErpContactAttachment::query()->whereKey($upload['id'])->update(['status' => 'deletion_pending']);
        $this->withHeaders($context['headers'])
            ->get("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$upload['id']}/download")
            ->assertStatus(409)
            ->assertJsonPath('code', 'note_deletion_pending');
        $this->withHeaders($context['headers'])
            ->getJson("/api/v1/erp/contacts/{$context['contact']->id}/notes?state=deletion_pending")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.state', 'deletion_pending')
            ->assertJsonPath('data.0.attachments_count', 1);
    }

    public function test_upload_outer_session_fence_rollback_removes_only_its_staged_key(): void
    {
        FailAccessSessionOwnershipAfterNextMiddleware::$responseStatusBeforeFence = null;
        FailAccessSessionOwnershipAfterNextMiddleware::$attachmentCountBeforeFence = null;
        FailAccessSessionOwnershipAfterNextMiddleware::$filesBeforeFence = [];
        $this->usePrivateAttachmentTestDisk();
        $disk = Storage::disk('erp-contact-attachments');
        $disk->put('accepted-sentinel', '%PDF-accepted');
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Rollback externo']);
        app('router')->getRoutes()->getByName('erp.contacts.notes.attachments.store')
            ->middleware(FailAccessSessionOwnershipAfterNextMiddleware::class);

        $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('rollback.pdf', "%PDF-1.4\nstaged\n"),
            ])
            ->assertStatus(503);

        $this->assertSame(201, FailAccessSessionOwnershipAfterNextMiddleware::$responseStatusBeforeFence);
        $this->assertSame(1, FailAccessSessionOwnershipAfterNextMiddleware::$attachmentCountBeforeFence);
        $this->assertCount(2, FailAccessSessionOwnershipAfterNextMiddleware::$filesBeforeFence);
        $this->assertSame(['accepted-sentinel'], $disk->allFiles());
        $this->assertSame(0, ErpContactAttachment::query()->where('erp_contact_note_id', $note['id'])->count());
        $this->assertSame('%PDF-accepted', $disk->get('accepted-sentinel'));
    }

    public function test_attachment_delete_outer_rollback_preserves_accepted_metadata_and_bytes(): void
    {
        FailAccessSessionOwnershipAfterNextMiddleware::$responseStatusBeforeFence = null;
        FailAccessSessionOwnershipAfterNextMiddleware::$attachmentStatusBeforeFence = null;
        FailAccessSessionOwnershipAfterNextMiddleware::$filesBeforeFence = [];
        $disk = $this->usePrivateAttachmentTestDisk();
        $context = $this->ownerContext();
        $note = $this->createNote($context, ['heading' => 'Rollback da remoção']);
        $bytes = "%PDF-1.4\narquivo aceito\n";
        $upload = $this->withHeaders($context['headers'])
            ->post("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('aceito.pdf', $bytes),
            ])
            ->assertCreated()
            ->json('data');
        $attachment = ErpContactAttachment::query()->findOrFail($upload['id']);
        app('router')->getRoutes()->getByName('erp.contacts.notes.attachments.archive')
            ->middleware(FailAccessSessionOwnershipAfterNextMiddleware::class);

        $this->withHeaders($context['headers'])
            ->deleteJson("/api/v1/erp/contacts/{$context['contact']->id}/notes/{$note['id']}/attachments/{$attachment->id}")
            ->assertStatus(503);

        $this->assertSame(202, FailAccessSessionOwnershipAfterNextMiddleware::$responseStatusBeforeFence);
        $this->assertSame('deletion_pending', FailAccessSessionOwnershipAfterNextMiddleware::$attachmentStatusBeforeFence);
        $this->assertContains($attachment->storage_key, FailAccessSessionOwnershipAfterNextMiddleware::$filesBeforeFence);
        $this->assertSame('ready', $attachment->fresh()->status);
        $stored = $disk->get($attachment->storage_key);
        $this->assertSame(strlen($bytes), strlen($stored));
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $stored));
    }

    /** @return array{user: User, company: ErpCompany, contact: ErpContact, headers: array<string, string>} */
    private function ownerContext(): array
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
        ]);
        $headers = $this->loginAndSelect($user, $company);
        $contact = ErpContact::factory()->create(['erp_company_id' => $company->id]);

        return compact('user', 'company', 'contact', 'headers');
    }

    /** @return array<string, string> */
    private function loginAndSelect(User $user, ErpCompany $company): array
    {
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk();

        return [
            'X-Company-Id' => (string) $company->id,
            'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'),
        ];
    }

    /** @return array<string, string> */
    private function loginAndSelectDelegated(User $user, Account $office, ErpCompany $company): array
    {
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/account/switch', ['account_id' => $office->id])
            ->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('current_company.access_kind', 'delegated');

        return [
            'X-Company-Id' => (string) $company->id,
            'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'),
        ];
    }

    /** @param array<string, string> $entries */
    private function zipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opmoni-task25-zip-');
        if (! is_string($path)) {
            throw new \RuntimeException('Unable to create a temporary ZIP fixture.');
        }

        $archive = new \ZipArchive;
        try {
            if ($archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to open a temporary ZIP fixture.');
            }
            foreach ($entries as $name => $contents) {
                if (! $archive->addFromString($name, $contents)) {
                    throw new \RuntimeException('Unable to write a temporary ZIP fixture.');
                }
            }
            if (! $archive->close()) {
                throw new \RuntimeException('Unable to close a temporary ZIP fixture.');
            }

            $bytes = file_get_contents($path);
            if (! is_string($bytes)) {
                throw new \RuntimeException('Unable to read a temporary ZIP fixture.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    /** @param array{user: User, company: ErpCompany, contact: ErpContact, headers: array<string, string>} $context
     * @param  array{heading?: string, description?: string, is_private?: bool}  $data
     * @return array{id: int}
     */
    private function createNote(array $context, array $data): array
    {
        return $this->withHeaders($context['headers'])
            ->postJson("/api/v1/erp/contacts/{$context['contact']->id}/notes", $data)
            ->assertCreated()
            ->json('data');
    }
}

class FailAccessSessionOwnershipAfterNextMiddleware
{
    public static ?int $responseStatusBeforeFence = null;

    public static ?string $attachmentStatusBeforeFence = null;

    public static ?int $attachmentCountBeforeFence = null;

    /** @var list<string> */
    public static array $filesBeforeFence = [];

    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        self::$responseStatusBeforeFence = $response->getStatusCode();
        self::$attachmentCountBeforeFence = DB::table('erp_contact_attachments')->count();
        self::$filesBeforeFence = Storage::disk('erp-contact-attachments')->allFiles();
        $attachmentId = $request->route('attachment');
        self::$attachmentStatusBeforeFence = $attachmentId === null
            ? null
            : DB::table('erp_contact_attachments')->where('id', $attachmentId)->value('status');
        $request->attributes->set('access.session_lock', new class
        {
            public function isOwnedByCurrentProcess(): bool
            {
                return false;
            }
        });

        return $response;
    }
}
