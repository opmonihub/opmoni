<?php

namespace Tests\Feature;

use App\Erp\ErpCatalogAttributionWriter;
use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpContactNoteService;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCatalogAttribution;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpContactAttachment;
use App\Models\Erp\ErpContactNote;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpContactNoteAttributionTest extends TestCase
{
    use RefreshDatabase;

    private FilesystemAdapter $disk;

    private string $testRoot;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Private attribution tests require isolated SQLite memory.');
        }
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $this->testRoot = sys_get_temp_dir().'/opmoni-contact-attribution-'.getmypid().'-'.bin2hex(random_bytes(12));
        $this->disk = Storage::build(['driver' => 'local', 'root' => $this->testRoot, 'serve' => false, 'throw' => false, 'report' => false]);
        Storage::set(ErpContactNoteService::DISK, $this->disk);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->testRoot)) {
                (new Filesystem)->deleteDirectory($this->testRoot);
            }
        }
    }

    public function test_direct_note_changes_bind_the_actual_actor_and_keep_content_free_history_after_physical_deletion(): void
    {
        [$company, $actor, $membership, $contact] = $this->owner();
        AccountMembership::factory()->create(['account_id' => Account::factory()->create()->id, 'user_id' => $actor->id, 'role' => 'admin']);
        $this->login($actor, $company);
        $this->assertNotNull($this->getJson('/api/v1/me')->assertOk()->json('current_account.id'));
        $url = $this->notesUrl($contact);
        $id = $this->postJson($url, ['heading' => 'Private heading sentinel', 'description' => 'Private body sentinel', 'is_private' => true])
            ->assertCreated()->assertJsonPath('data.can_change_privacy', true)->json('data.id');
        $this->assertDatabaseCount('erp_catalog_attributions', 1);
        $first = DB::table('erp_catalog_attributions')->sole();
        $this->assertEqualsCanonicalizing([
            'id', 'erp_company_id', 'actor_user_id', 'access_kind', 'erp_company_membership_id',
            'account_id', 'erp_office_delegation_id', 'action', 'resource_type', 'resource_id', 'batch_id', 'recorded_at',
        ], Schema::getColumnListing('erp_catalog_attributions'));
        $this->assertSame($company->id, $first->erp_company_id);
        $this->assertSame($actor->id, $first->actor_user_id);
        $this->assertSame($membership->id, $first->erp_company_membership_id);
        $this->assertSame('direct', $first->access_kind);
        $this->assertNull($first->account_id);
        $this->assertNull($first->erp_office_delegation_id);
        $this->assertNull($first->batch_id);
        $this->assertNotNull($first->recorded_at);
        $this->assertSame($id, $first->resource_id);
        $this->assertSame('contact_note', $first->resource_type);
        $this->assertSame('created', $first->action);
        $this->assertSame($actor->id, ErpContactNote::findOrFail($id)->created_by);
        $this->travel(5)->seconds();
        $this->patchJson($url.'/'.$id, [])->assertOk();
        $this->patchJson($url.'/'.$id, ['heading' => 'Private heading sentinel', 'is_private' => true])->assertOk();
        $this->assertDatabaseCount('erp_catalog_attributions', 1);
        $this->patchJson($url.'/'.$id, ['heading' => 'Private changed sentinel'])->assertOk();
        $this->patchJson($url.'/'.$id, ['is_private' => false])->assertOk();
        $this->travel(5)->seconds();
        $this->patchJson($url.'/'.$id, ['is_private' => false])->assertOk();
        $this->getJson($url)->assertOk();
        $this->getJson($url.'/'.$id)->assertOk();
        $this->assertDatabaseCount('erp_catalog_attributions', 3);
        $history = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
        $this->deleteJson($url.'/'.$id)->assertAccepted();
        $this->assertNull(ErpContactNote::find($id), 'Physical removal must run after the accepted transaction.');
        $this->assertSame(['created', 'updated', 'updated', 'deletion_requested'], DB::table('erp_catalog_attributions')->orderBy('id')->pluck('action')->all());
        $this->assertSame(['contact_note', 'contact_note', 'contact_note', 'contact_note'], DB::table('erp_catalog_attributions')->orderBy('id')->pluck('resource_type')->all());
        $this->assertSame([$id, $id, $id, $id], DB::table('erp_catalog_attributions')->orderBy('id')->pluck('resource_id')->all());
        $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->limit(3)->get()->all());
        $membership->delete();
        $contact->delete();
        $this->assertEquals($first, DB::table('erp_catalog_attributions')->where('id', $first->id)->first());
        $this->assertStringNotContainsString('sentinel', json_encode(DB::table('erp_catalog_attributions')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_attachment_delete_retries_add_zero_events_and_history_survives_real_byte_removal(): void
    {
        [, , , $contact] = $this->owner();
        $note = $this->createNote($contact);
        $bytes = "%PDF-1.4\nprivate attachment sentinel\n";
        $attachment = $this->upload($contact, $note, $bytes);
        $url = $this->notesUrl($contact).'/'.$note.'/attachments/'.$attachment->id;
        $this->assertSame($bytes, $this->disk->get($attachment->storage_key));
        $this->assertSame(strlen($bytes), (int) $attachment->byte_length);
        $this->assertSame(hash('sha256', $bytes), $attachment->sha256);
        $this->assertSame(['created', 'attachment_ready_created'], DB::table('erp_catalog_attributions')->orderBy('id')->pluck('action')->all());
        $native = app(Filesystem::class);
        $blocked = \Mockery::mock($native)->makePartial();
        $blocked->shouldReceive('delete')->twice()->with($this->disk->path($attachment->storage_key))->andReturn(false);
        app()->instance(Filesystem::class, $blocked);
        try {
            $this->deleteJson($url)->assertAccepted()->assertJsonPath('data.status', 'deletion_pending');
            $this->assertSame('deletion_pending', $attachment->fresh()->status);
            $this->assertSame($bytes, $this->disk->get($attachment->storage_key));
            $this->assertDatabaseCount('erp_catalog_attributions', 3);
            $history = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
            $this->travel(5)->seconds();
            $this->deleteJson($url)->assertAccepted();
            $this->getJson($this->notesUrl($contact).'?state=deletion_pending')->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson($url.'/download')->assertStatus(409);
            $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
        } finally {
            app()->instance(Filesystem::class, $native);
        }
        $this->deleteJson($url)->assertAccepted();
        $this->assertNull($attachment->fresh());
        $this->assertFalse($this->disk->exists($attachment->storage_key));
        $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
        $this->assertSame('active', ErpContactNote::findOrFail($note)->state);
        $last = DB::table('erp_catalog_attributions')->orderByDesc('id')->first();
        $this->assertSame('deletion_requested', $last->action);
        $this->assertSame('contact_note_attachment', $last->resource_type);
        $this->assertSame($attachment->id, $last->resource_id);
        $events = json_encode($history, JSON_THROW_ON_ERROR);
        foreach (['private', 'sentinel', $attachment->original_name, $attachment->mime_type, $attachment->sha256, $attachment->storage_key] as $secret) {
            $this->assertStringNotContainsString($secret, $events);
        }
    }

    public function test_note_deletion_attributes_each_actual_ready_or_unavailable_transition_once_not_pending_or_foreign_rows(): void
    {
        [$company, $actor, , $contact] = $this->owner();
        $note = $this->createNote($contact);
        $ready = $this->upload($contact, $note);
        $unavailable = ErpContactAttachment::factory()->create(['erp_company_id' => $company->id, 'erp_contact_note_id' => $note, 'status' => 'unavailable']);
        $pending = ErpContactAttachment::factory()->create(['erp_company_id' => $company->id, 'erp_contact_note_id' => $note, 'status' => 'deletion_pending']);
        $otherNote = ErpContactNote::factory()->create(['erp_company_id' => $company->id, 'erp_contact_id' => $contact->id, 'created_by' => $actor->id]);
        $other = ErpContactAttachment::factory()->create(['erp_company_id' => $company->id, 'erp_contact_note_id' => $otherNote->id, 'status' => 'ready']);
        $foreign = ErpContactAttachment::factory()->create(['status' => 'ready']);
        foreach ([$unavailable, $pending, $other, $foreign] as $row) {
            $this->assertTrue($this->disk->put($row->storage_key, '%PDF-protected-'.$row->id));
        }
        $before = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
        $native = app(Filesystem::class);
        $blocked = \Mockery::mock($native)->makePartial();
        $blocked->shouldReceive('delete')->andReturn(false);
        app()->instance(Filesystem::class, $blocked);
        try {
            $url = $this->notesUrl($contact).'/'.$note;
            $this->deleteJson($url)->assertAccepted();
            $events = DB::table('erp_catalog_attributions')->orderBy('id')->get()->slice(count($before))->values();
            $this->assertSame(['contact_note', 'contact_note_attachment', 'contact_note_attachment'], $events->pluck('resource_type')->all());
            $this->assertSame([$note, $ready->id, $unavailable->id], $events->pluck('resource_id')->all());
            $this->assertSame(['deletion_requested', 'deletion_requested', 'deletion_requested'], $events->pluck('action')->all());
            $history = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
            $this->deleteJson($url)->assertAccepted();
            $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
            $this->assertSame('deletion_pending', ErpContactNote::findOrFail($note)->state);
            foreach ([$ready, $unavailable, $pending] as $row) {
                $this->assertSame('deletion_pending', $row->fresh()->status);
                $this->assertTrue($this->disk->exists($row->storage_key));
            }
            $this->getJson($url)->assertOk()->assertJsonPath('data.deletion_pending_attachments_count', 3);
        } finally {
            app()->instance(Filesystem::class, $native);
        }
        $this->deleteJson($url)->assertAccepted();
        $this->assertNull(ErpContactNote::find($note));
        foreach ([$ready, $unavailable, $pending] as $row) {
            $this->assertNull($row->fresh());
            $this->assertFalse($this->disk->exists($row->storage_key));
        }
        foreach ([$other, $foreign] as $row) {
            $this->assertSame('ready', $row->fresh()->status);
            $this->assertSame('%PDF-protected-'.$row->id, $this->disk->get($row->storage_key));
        }
        $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
        $this->assertEquals($before, DB::table('erp_catalog_attributions')->orderBy('id')->limit(count($before))->get()->all());
    }

    public static function storageFailures(): array
    {
        return ['write refusal' => ['put'], 'read refusal' => ['read'], 'same-length wrong hash' => ['hash']];
    }

    #[DataProvider('storageFailures')]
    public function test_unavailable_503_retains_truthful_new_metadata_attribution_without_ready_claims(string $failure): void
    {
        [$company, $actor, $membership, $contact] = $this->owner();
        $note = $this->createNote($contact);
        $this->assertTrue($this->disk->put('sentinel', 'accepted bytes'));
        $manager = app('filesystem');
        $broken = \Mockery::mock($this->disk)->makePartial();
        if ($failure === 'put') {
            $broken->shouldReceive('put')->once()->andReturn(false);
        } elseif ($failure === 'read') {
            $broken->shouldReceive('readStream')->once()->andReturn(false);
        } else {
            $broken->shouldReceive('readStream')->once()->andReturnUsing(function () {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, "%PDF-1.4\nwrong\n");
                rewind($stream);

                return $stream;
            });
        }
        Storage::partialMock()->shouldReceive('disk')->with(ErpContactNoteService::DISK)->andReturn($broken);
        try {
            $id = $this->post($this->notesUrl($contact).'/'.$note.'/attachments', ['file' => UploadedFile::fake()->createWithContent('private-sentinel.pdf', "%PDF-1.4\nright\n")], ['Accept' => 'application/json'])
                ->assertStatus(503)->assertJsonPath('code', 'attachment_unavailable')->assertJsonPath('data.status', 'unavailable')->json('data.id');
        } finally {
            Storage::swap($manager);
        }
        $attachment = ErpContactAttachment::findOrFail($id);
        $this->assertSame('unavailable', $attachment->status);
        $this->assertSame(['sentinel'], $this->disk->allFiles());
        $this->assertSame('accepted bytes', $this->disk->get('sentinel'));
        $this->assertDatabaseCount('erp_catalog_attributions', 2);
        $event = DB::table('erp_catalog_attributions')->orderByDesc('id')->first();
        $this->assertSame('attachment_unavailable_created', $event->action);
        $this->assertSame('contact_note_attachment', $event->resource_type);
        $this->assertSame($id, $event->resource_id);
        $this->assertSame($company->id, $event->erp_company_id);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($membership->id, $event->erp_company_membership_id);
        $this->assertNull($event->account_id);
        $this->assertNull($event->erp_office_delegation_id);
        $this->assertNull($event->batch_id);
        $this->assertStringNotContainsString('private', json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($attachment->storage_key, json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_reads_download_and_integrity_repair_never_add_attribution(): void
    {
        [, , , $contact] = $this->owner();
        $note = $this->createNote($contact);
        $bytes = "%PDF-1.4\naccepted bytes\n";
        $attachment = $this->upload($contact, $note, $bytes);
        $this->assertDatabaseCount('erp_catalog_attributions', 2);
        $history = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
        $url = $this->notesUrl($contact).'/'.$note;
        $this->getJson($this->notesUrl($contact))->assertOk();
        $this->getJson($url)->assertOk();
        $download = $this->getJson($url.'/attachments/'.$attachment->id.'/download')->assertOk();
        $this->assertSame($bytes, $download->getContent());
        $this->assertTrue($this->disk->put($attachment->storage_key, "%PDF-1.4\ncorrupted data\n"));
        $this->getJson($url.'/attachments/'.$attachment->id.'/download')->assertStatus(503)->assertJsonPath('code', 'attachment_unavailable');
        $this->assertSame('unavailable', $attachment->fresh()->status);
        $this->getJson($url.'/attachments/'.$attachment->id.'/download')->assertStatus(503);
        $this->getJson($url)->assertOk();
        $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
    }

    public function test_delegated_note_and_attachment_writes_bind_the_exact_current_office_and_real_author(): void
    {
        [$company, $owner, , $contact] = $this->owner();
        $private = $this->createNote($contact);
        $delegate = User::factory()->create();
        $office = Account::factory()->create();
        $member = AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $delegate->id, 'role' => 'admin']);
        $permissions = ['erp.read', 'erp.contacts.notes.store', 'erp.contacts.notes.update', 'erp.contacts.notes.archive', 'erp.contacts.notes.attachments.store', 'erp.contacts.notes.attachments.archive'];
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id, 'authorized_by_user_id' => $owner->id, 'permission_allowlist' => $permissions]);
        $this->login($delegate, $company);
        $this->patchJson($this->notesUrl($contact).'/'.$private, ['heading' => 'Not the author'])->assertNotFound();
        $note = $this->createNote($contact);
        $this->assertSame($delegate->id, ErpContactNote::findOrFail($note)->created_by);
        $this->patchJson($this->notesUrl($contact).'/'.$note, ['description' => 'private delegated sentinel'])->assertOk();
        $attachment = $this->upload($contact, $note);
        $this->deleteJson($this->notesUrl($contact).'/'.$note.'/attachments/'.$attachment->id)->assertAccepted();
        $this->deleteJson($this->notesUrl($contact).'/'.$note)->assertAccepted();
        $events = DB::table('erp_catalog_attributions')->where('actor_user_id', $delegate->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'attachment_ready_created', 'deletion_requested', 'deletion_requested'], $events->pluck('action')->all());
        $this->assertSame(['contact_note', 'contact_note', 'contact_note_attachment', 'contact_note_attachment', 'contact_note'], $events->pluck('resource_type')->all());
        $this->assertSame([$note, $note, $attachment->id, $attachment->id, $note], $events->pluck('resource_id')->all());
        foreach ($events as $event) {
            $this->assertSame($company->id, $event->erp_company_id);
            $this->assertSame($delegate->id, $event->actor_user_id);
            $this->assertSame('delegated', $event->access_kind);
            $this->assertSame($office->id, $event->account_id);
            $this->assertSame($delegation->id, $event->erp_office_delegation_id);
            $this->assertNull($event->erp_company_membership_id);
            $this->assertNull($event->batch_id);
        }
        $history = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
        $delegation->update(['permission_allowlist' => ['erp.read', 'erp.contacts.notes.index']]);
        $this->postJson($this->notesUrl($contact), ['heading' => 'Not explicitly granted'])->assertForbidden();
        $delegation->update(['permission_allowlist' => $permissions]);
        $member->update(['role' => 'user']);
        $this->postJson($this->notesUrl($contact), ['heading' => 'Read only'])->assertForbidden();
        $member->update(['role' => 'admin']);
        $delegation->update(['status' => 'revoked']);
        ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id, 'permission_allowlist' => $permissions]);
        $this->postJson($this->notesUrl($contact), ['heading' => 'Replacement not selected'])->assertForbidden();
        $delegation->delete();
        $member->delete();
        $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
    }

    public static function nonAuthors(): array
    {
        return ['owner wildcard' => ['owner', false], 'manager wildcard' => ['manager', false], 'platform and owner' => ['owner', true]];
    }

    #[DataProvider('nonAuthors')]
    public function test_private_author_boundary_denies_reads_and_every_write_without_new_events(string $role, bool $platform): void
    {
        [$company, , , $contact] = $this->owner();
        $note = $this->createNote($contact);
        $attachment = $this->upload($contact, $note);
        $history = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
        $stranger = User::factory()->create(['is_super_admin' => $platform]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $stranger->id, 'role' => $role]);
        $this->login($stranger, $company);
        $url = $this->notesUrl($contact).'/'.$note;
        $this->getJson($this->notesUrl($contact))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($url)->assertNotFound()->assertDontSee('Private');
        $this->getJson($url.'/attachments/'.$attachment->id.'/download')->assertNotFound();
        $this->patchJson($url, ['heading' => 'Denied'])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->post($url.'/attachments', ['file' => UploadedFile::fake()->createWithContent('denied.pdf', "%PDF-1.4\n")], ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson($url.'/attachments/'.$attachment->id)->assertNotFound();
        $this->assertSame('active', ErpContactNote::findOrFail($note)->state);
        $this->assertSame('ready', $attachment->fresh()->status);
        $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
    }

    public function test_shared_note_uses_mutating_actor_not_original_author_and_denials_add_no_events(): void
    {
        [$company, , , $contact] = $this->owner();
        $note = $this->postJson($this->notesUrl($contact), ['heading' => 'Shared', 'is_private' => false])->assertCreated()->json('data.id');
        $actor = User::factory()->create();
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'manager']);
        $this->login($actor, $company);
        $url = $this->notesUrl($contact).'/'.$note;
        $this->patchJson($url, ['is_private' => true])->assertForbidden();
        $this->patchJson($url, ['created_by' => $actor->id])->assertUnprocessable();
        $this->postJson($this->notesUrl($contact), [])->assertUnprocessable();
        $this->patchJson($url, ['heading' => 'Actual editor'])->assertOk();
        $this->assertDatabaseCount('erp_catalog_attributions', 2);
        $event = DB::table('erp_catalog_attributions')->orderByDesc('id')->first();
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($membership->id, $event->erp_company_membership_id);
        $this->assertSame('updated', $event->action);
        $this->assertSame($note, $event->resource_id);
        $history = DB::table('erp_catalog_attributions')->orderBy('id')->get()->all();
        $foreign = ErpContact::factory()->create();
        $otherContact = ErpContact::factory()->create(['erp_company_id' => $company->id]);
        $this->patchJson($this->notesUrl($foreign).'/'.$note, ['heading' => 'Foreign'])->assertNotFound();
        $this->deleteJson($this->notesUrl($otherContact).'/'.$note)->assertNotFound();
        $this->withHeader('X-CSRF-Token', 'invalid')->patchJson($url, ['heading' => 'No CSRF'])->assertForbidden();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $membership->update(['role' => 'viewer']);
        $this->patchJson($url, ['heading' => 'Read only'])->assertForbidden();
        $membership->delete();
        $this->patchJson($url, ['heading' => 'Revoked'])->assertForbidden();
        $this->assertEquals($history, DB::table('erp_catalog_attributions')->orderBy('id')->get()->all());
    }

    public static function transactionFailures(): array
    {
        $cases = [];
        foreach (['note create', 'note update', 'ready upload', 'unavailable upload', 'note delete', 'attachment delete'] as $operation) {
            foreach (['insertion', 'veto', 'after insertion', 'fence'] as $failure) {
                $cases[$operation.' '.$failure] = [$operation, $failure];
            }
        }

        return $cases;
    }

    public static function invalidWriterProofs(): array
    {
        return [
            'ready action on unavailable' => ['unavailable', 'attachment_ready_created', 'none'],
            'unavailable action on ready' => ['ready', 'attachment_unavailable_created', 'none'],
            'existing ready is not creation' => ['ready', 'attachment_ready_created', 'reload'],
            'existing unavailable is not creation' => ['unavailable', 'attachment_unavailable_created', 'reload'],
            'ready model contradicts persisted unavailable' => ['unavailable', 'attachment_ready_created', 'ready'],
            'unavailable model contradicts persisted ready' => ['ready', 'attachment_unavailable_created', 'unavailable'],
            'generic attachment creation is not truthful' => ['ready', 'created', 'none'],
            'attachment archive is not deletion request' => ['ready', 'archived', 'none'],
            'note cannot use attachment action' => ['ready', 'attachment_ready_created', 'note'],
            'note archive is not deletion request' => ['ready', 'archived', 'note'],
            'note pending must be persisted' => ['ready', 'deletion_requested', 'note pending'],
            'attachment pending must be persisted' => ['ready', 'deletion_requested', 'attachment pending'],
            'foreign note ownership' => ['ready', 'created', 'foreign note'],
            'foreign attachment ownership' => ['ready', 'attachment_ready_created', 'foreign attachment'],
        ];
    }

    #[DataProvider('invalidWriterProofs')]
    public function test_writer_rejects_untruthful_creation_deletion_or_ownership_inside_actual_http_boundary(string $status, string $action, string $proof): void
    {
        [$company, $actor, , $contact] = $this->owner();
        $note = ErpContactNote::factory()->create(['erp_company_id' => $company->id, 'erp_contact_id' => $contact->id, 'created_by' => $actor->id, 'heading' => 'Original', 'state' => 'active']);
        $resource = ErpContactAttachment::factory()->create(['erp_company_id' => $company->id, 'erp_contact_note_id' => $note->id, 'status' => $status]);
        if ($proof === 'reload') {
            $resource = $resource->fresh();
        } elseif (in_array($proof, ['ready', 'unavailable'], true)) {
            $resource->status = $proof;
        } elseif ($proof === 'foreign note') {
            $resource = ErpContactNote::factory()->create();
        } elseif ($proof === 'foreign attachment') {
            $resource = ErpContactAttachment::factory()->create(['status' => 'ready']);
        } elseif (in_array($proof, ['note', 'note pending'], true)) {
            $resource = $note;
            if ($proof === 'note pending') {
                $resource->state = 'deletion_pending';
                $resource->syncChanges();
            }
        } elseif ($proof === 'attachment pending') {
            $resource->status = 'deletion_pending';
            $resource->syncChanges();
        }
        $rejected = false;
        $eventName = 'eloquent.updating: '.ErpContactNote::class;
        Event::listen($eventName, function () use ($resource, $action, &$rejected): void {
            try {
                app(ErpCatalogAttributionWriter::class)->record(request(), CurrentCompany::fromRequest(), $resource, $action);
            } catch (\LogicException $failure) {
                $rejected = true;
                throw $failure;
            }
        });
        try {
            $this->patchJson($this->notesUrl($contact).'/'.$note->id, ['heading' => 'Must roll back'])->assertStatus(503);
        } finally {
            Event::forget($eventName);
        }
        $this->assertTrue($rejected);
        $this->assertSame('Original', $note->fresh()->heading);
        $this->assertDatabaseCount('erp_catalog_attributions', 0);
    }

    #[DataProvider('transactionFailures')]
    public function test_failed_http_mutation_rolls_back_metadata_history_and_bytes_together(string $operation, string $failure): void
    {
        [, , , $contact] = $this->owner();
        $note = $this->createNote($contact);
        $acceptedBytes = "%PDF-1.4\naccepted private sentinel\n";
        $attachment = $this->upload($contact, $note, $acceptedBytes);
        $this->assertTrue($this->disk->put('accepted-sentinel', 'unrelated bytes'));
        $before = [];
        foreach (['erp_contact_notes', 'erp_contact_attachments', 'erp_catalog_attributions'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->all();
        }
        $manager = app('filesystem');
        if ($operation === 'unavailable upload') {
            $broken = \Mockery::mock($this->disk)->makePartial();
            $broken->shouldReceive('put')->once()->andReturn(false);
            Storage::partialMock()->shouldReceive('disk')->with(ErpContactNoteService::DISK)->andReturn($broken);
        }
        $eventName = 'eloquent.'.(in_array($failure, ['insertion', 'veto'], true) ? 'creating' : 'created').': '.ErpCatalogAttribution::class;
        $expectedEvents = $operation === 'note delete' ? 2 : 1;
        $seen = 0;
        $protected = false;
        $stagedKey = null;
        $stagedBytes = null;
        $pendingBeforeRollback = null;
        Event::listen($eventName, function () use ($operation, $failure, $attachment, $expectedEvents, &$seen, &$protected, &$stagedKey, &$stagedBytes, &$pendingBeforeRollback): ?bool {
            if (++$seen !== $expectedEvents) {
                return null;
            }
            $boundary = request()->attributes->get(ErpCatalogWriteService::class);
            $protected = is_array($boundary)
                && ($boundary['context'] ?? null) === CurrentCompany::fromRequest()
                && ($boundary['connection'] ?? null) === DB::connection()
                && ($boundary['level'] ?? 0) > 0
                && $boundary['level'] <= DB::transactionLevel();
            if (in_array($operation, ['ready upload', 'unavailable upload'], true)) {
                $staged = ErpContactAttachment::query()->orderByDesc('id')->firstOrFail();
                $stagedKey = $staged->storage_key;
                $stagedBytes = $this->disk->exists($stagedKey) ? $this->disk->get($stagedKey) : null;
            }
            if (in_array($operation, ['note delete', 'attachment delete'], true)) {
                $pendingBeforeRollback = $attachment->fresh()->status;
                $this->assertSame('%PDF-1.4'."\naccepted private sentinel\n", $this->disk->get($attachment->storage_key));
            }
            if ($failure === 'veto') {
                return false;
            }
            if ($failure === 'fence') {
                request()->attributes->set('access.session_lock', new class
                {
                    public function isOwnedByCurrentProcess(): bool
                    {
                        return false;
                    }
                });

                return null;
            }
            throw new \RuntimeException('Injected attribution persistence failure');
        });
        try {
            $url = $this->notesUrl($contact).'/'.$note;
            match ($operation) {
                'note create' => $this->postJson($this->notesUrl($contact), ['heading' => 'Rollback new note'])->assertStatus(503),
                'note update' => $this->patchJson($url, ['heading' => 'Rollback heading', 'is_private' => false])->assertStatus(503),
                'ready upload', 'unavailable upload' => $this->post($url.'/attachments', ['file' => UploadedFile::fake()->createWithContent('rollback.pdf', "%PDF-1.4\nstaged private sentinel\n")], ['Accept' => 'application/json'])->assertStatus(503),
                'note delete' => $this->deleteJson($url)->assertStatus(503),
                'attachment delete' => $this->deleteJson($url.'/attachments/'.$attachment->id)->assertStatus(503),
            };
        } finally {
            Event::forget($eventName);
            Storage::swap($manager);
        }
        $this->assertSame($expectedEvents, $seen, 'The failure must follow actual metadata persistence, including the last cascade event.');
        $this->assertTrue($protected);
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->orderBy('id')->get()->all(), $table.' must roll back without touching accepted history.');
        }
        if (in_array($operation, ['ready upload', 'unavailable upload'], true)) {
            $this->assertIsString($stagedKey);
            $this->assertFalse($this->disk->exists($stagedKey), 'Failed upload must compensate only its new private key.');
            $this->assertSame($operation === 'ready upload' ? "%PDF-1.4\nstaged private sentinel\n" : null, $stagedBytes);
        }
        if (in_array($operation, ['note delete', 'attachment delete'], true)) {
            $this->assertSame('deletion_pending', $pendingBeforeRollback);
        }
        $this->assertEqualsCanonicalizing(['accepted-sentinel', $attachment->storage_key], $this->disk->allFiles());
        $this->assertSame($acceptedBytes, $this->disk->get($attachment->storage_key));
        $this->assertSame('unrelated bytes', $this->disk->get('accepted-sentinel'));
    }

    /** @return array{ErpCompany, User, ErpCompanyMembership, ErpContact} */
    private function owner(): array
    {
        $company = ErpCompany::factory()->create();
        ErpSubscription::factory()->active()->create(['erp_company_id' => $company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id]);
        $actor = User::factory()->create();
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'owner']);
        $contact = ErpContact::factory()->create(['erp_company_id' => $company->id]);
        $this->login($actor, $company);

        return [$company, $actor, $membership, $contact];
    }

    private function login(User $actor, ErpCompany $company): void
    {
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
    }

    private function notesUrl(ErpContact $contact): string
    {
        return '/api/v1/erp/contacts/'.$contact->id.'/notes';
    }

    private function createNote(ErpContact $contact): int
    {
        return $this->postJson($this->notesUrl($contact), ['heading' => 'Private heading', 'description' => 'Private body', 'is_private' => true])
            ->assertCreated()->json('data.id');
    }

    private function upload(ErpContact $contact, int $note, string $bytes = "%PDF-1.4\naccepted\n"): ErpContactAttachment
    {
        $id = $this->post($this->notesUrl($contact).'/'.$note.'/attachments', ['file' => UploadedFile::fake()->createWithContent('private-sentinel.pdf', $bytes)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'ready')->json('data.id');

        return ErpContactAttachment::findOrFail($id);
    }
}
