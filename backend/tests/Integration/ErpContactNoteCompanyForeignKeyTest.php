<?php

namespace Tests\Integration;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpContactAttachment;
use App\Models\Erp\ErpContactNote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ErpContactNoteCompanyForeignKeyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('opmoni_test', DB::selectOne('select current_database() as name')->name);
        $this->assertSame('13', (string) config('database.redis.default.database'));
        $this->assertSame('14', (string) config('database.redis.cache.database'));
        $this->assertSame('15', (string) config('database.redis.sessions.database'));
        $this->assertSame(13, Redis::connection('default')->client()->getDBNum());
        $this->assertSame(14, Redis::connection('cache')->client()->getDBNum());
        $this->assertSame(15, Redis::connection('sessions')->client()->getDBNum());
    }

    public function test_factories_and_composite_foreign_keys_keep_notes_and_attachments_in_the_parent_company(): void
    {
        $cnpjBase = random_int(80000000000000, 89999999999990);
        $companyA = ErpCompany::factory()->create(['cnpj' => (string) $cnpjBase]);
        $companyB = ErpCompany::factory()->create(['cnpj' => (string) ($cnpjBase + 1)]);
        $contact = ErpContact::factory()->create(['erp_company_id' => $companyA->id]);
        $author = User::factory()->create();
        $note = ErpContactNote::factory()->create([
            'erp_contact_id' => $contact->id,
            'created_by' => $author->id,
        ]);
        $attachment = ErpContactAttachment::factory()->create(['erp_contact_note_id' => $note->id]);

        $this->assertSame($companyA->id, (int) $note->erp_company_id);
        $this->assertSame($companyA->id, (int) $attachment->erp_company_id);

        $foreignNoteError = null;
        try {
            DB::transaction(fn () => DB::table('erp_contact_notes')->insert([
                'erp_company_id' => $companyB->id,
                'erp_contact_id' => $contact->id,
                'created_by' => $author->id,
                'heading' => 'cross-company note',
                'is_private' => false,
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (QueryException $exception) {
            $foreignNoteError = $exception;
        }
        $this->assertInstanceOf(QueryException::class, $foreignNoteError);
        $this->assertSame('23503', (string) $foreignNoteError->getCode());

        $foreignAttachmentError = null;
        try {
            DB::transaction(fn () => DB::table('erp_contact_attachments')->insert([
                'erp_company_id' => $companyB->id,
                'erp_contact_note_id' => $note->id,
                'original_name' => 'cross-company.pdf',
                'mime_type' => 'application/pdf',
                'byte_length' => 1,
                'sha256' => hash('sha256', 'x'),
                'storage_key' => bin2hex(random_bytes(32)),
                'status' => 'unavailable',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (QueryException $exception) {
            $foreignAttachmentError = $exception;
        }
        $this->assertInstanceOf(QueryException::class, $foreignAttachmentError);
        $this->assertSame('23503', (string) $foreignAttachmentError->getCode());
    }
}
