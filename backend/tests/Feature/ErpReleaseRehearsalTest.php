<?php

namespace Tests\Feature;

use App\Erp\ErpReleaseGate;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ErpReleaseRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_migrations_preserve_existing_fiscal_rows_after_refresh(): void
    {
        Storage::fake('local');
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $path = 'erp/fiscal/preserve.xml';
        Storage::disk('local')->put($path, '<nfeProc/>');
        ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => ErpEstablishment::factory()->create(['erp_company_id' => $company->id])->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 1,
            'access_key' => '35251030288513000100550010000000011234567890',
            'status' => 'authorized',
            'idempotency_key' => 'preserve-1',
            'storage_path' => $path,
            'artifact_byte_length' => 10,
            'artifact_sha256' => hash('sha256', '<nfeProc/>'),
            'authorized_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(1, ErpFiscalDocument::query()->count());
        $this->assertSame('authorized', ErpFiscalDocument::query()->value('status'));
    }

    public function test_disable_release_mode_fails_closed_without_deleting_data(): void
    {
        config([
            'erp.release_enabled' => false,
            'erp.release_mode' => 'disabled',
        ]);
        $gate = app(ErpReleaseGate::class);
        $this->assertFalse($gate->isReleased());
        $this->assertSame('disabled', $gate->mode());

        $company = ErpCompany::factory()->create();
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'number' => 2,
            'idempotency_key' => 'still-here',
        ]);
        $this->assertSame(1, ErpFiscalDocument::query()->count());
    }
}
