<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Illuminate\Support\Facades\DB;
use Tests\OfficeErpCommercialTestCase;

class CompanyProfileTest extends OfficeErpCommercialTestCase
{
    private function fixture(): array
    {
        $ctx = $this->officeContext();
        $client = $this->clientFor($ctx);
        $company = ErpCompany::factory()->create(['cnpj' => $client->tax_id, 'owner_setup_status' => 'unclaimed', 'operating_settings' => ['regime' => 3]]);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id]);
        OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $client->id, 'erp_company_id' => $company->id]);
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'profile-save');

        return [$ctx, $client, $company];
    }

    public function test_profile_saves_source_owned_fields_atomically_and_supports_long_legal_names(): void
    {
        [$ctx, $client, $company] = $this->fixture();
        $before = hash('sha256', DB::table('users')->orderBy('id')->get()->toJson());
        $version = $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->assertOk()->json('data.profile_version');
        $name = str_repeat('A', 200);
        $response = $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id, [
            'expected_profile_version' => $version, 'name' => $name, 'trade_name' => 'Empresa Comercial',
            'tax_regime' => 'actual_profit', 'email' => 'contato@example.test', 'contact_person' => 'Contato comercial', 'whatsapp' => '11999990000',
            'street' => 'Rua Nova', 'address_number' => '21', 'postal_code' => '01001000', 'city' => 'São Paulo', 'state' => 'SP', 'ie' => '123456789',
        ])->assertOk()->assertJsonPath('data.profile.name', $name)->assertJsonPath('data.profile.tax_regime', 'actual_profit')->assertJsonPath('data.profile.contact_person', 'Contato comercial');
        self::assertNotSame($version, $response->json('data.profile_version'));
        self::assertSame('Empresa Comercial', $company->fresh()->name);
        self::assertSame($name, $company->fresh()->razao_social);
        self::assertSame(['regime' => 3], $company->fresh()->operating_settings);
        self::assertSame($client->tax_id, $company->fresh()->cnpj);
        self::assertSame($before, hash('sha256', DB::table('users')->orderBy('id')->get()->toJson()));
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => $name, 'email' => 'contato@example.test', 'whatsapp' => '11999990000']);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('erp_company_memberships', 0);
    }

    public function test_same_second_source_changes_reject_a_stale_profile_without_overwriting_it(): void
    {
        [$ctx, $client, $company] = $this->fixture();
        $version = $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->json('data.profile_version');
        DB::table('clients')->where('id', $client->id)->update(['phone' => '11888880000']);
        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id, ['expected_profile_version' => $version, 'phone' => '11777770000'])->assertConflict();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'phone' => '11888880000']);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }

    public function test_profile_preserves_short_legacy_names_on_contact_only_patch_and_recovers_receipt(): void
    {
        [$ctx, $client, $company] = $this->fixture();
        DB::table('clients')->where('id', $client->id)->update(['name' => 'ABC']);
        $version = $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->json('data.profile_version');
        $payload = ['expected_profile_version' => $version, 'phone' => '11999990000'];
        $first = $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id, $payload)->assertOk();
        $again = $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id, $payload)->assertOk();
        self::assertSame($first->json('data'), $again->json('data'));
        $this->getJson('/api/v1/erp-commercial/operations/profile-save')->assertOk();
        self::assertSame($company->name, $company->fresh()->name);
        self::assertNull($company->fresh()->razao_social);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'ABC', 'phone' => '11999990000']);
    }

    public function test_company_projection_conflict_is_detected_without_a_timestamp_change(): void
    {
        [$ctx, $client, $company] = $this->fixture();
        $version = $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->json('data.profile_version');
        DB::table('erp_companies')->where('id', $company->id)->update(['ie' => '987654321']);
        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id, ['expected_profile_version' => $version, 'phone' => '11999990000'])->assertConflict();
        self::assertSame('987654321', $company->fresh()->ie);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'phone' => $client->phone]);
    }

    public function test_company_save_failure_rolls_back_the_client_and_operation_receipt(): void
    {
        [$ctx, $client, $company] = $this->fixture();
        $version = $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->json('data.profile_version');
        ErpCompany::updating(static function (ErpCompany $candidate) use ($company): void {
            if ($candidate->id === $company->id) {
                throw new \RuntimeException('Controlled Company persistence failure.');
            }
        });
        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id, ['expected_profile_version' => $version, 'phone' => '11999990000', 'ie' => '123456789'])->assertStatus(503);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'phone' => $client->phone]);
        self::assertNull($company->fresh()->ie);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }

    public function test_invalid_and_immutable_fields_have_named_errors_and_no_partial_save(): void
    {
        [$ctx, $client, $company] = $this->fixture();
        $version = $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->json('data.profile_version');
        foreach ([['email' => 'invalid'], ['name' => 'curta'], ['cnpj' => '11222333000181'], ['password' => 'forbidden'], ['ie' => str_repeat('1', 21)], ['state' => 'SPP']] as $invalid) {
            $field = array_key_first($invalid);
            $response = $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id, ['expected_profile_version' => $version, 'phone' => '11999990000', ...$invalid])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            if ($field === 'email') {
                $response->assertJsonPath('errors.email.0', 'Informe um e-mail válido.');
            }
        }
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'phone' => $client->phone]);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }
}
