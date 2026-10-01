<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ProcessTemplate;
use App\Models\SupportAccessLog;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkSupportAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_support_writes_on_work_create_update_delete_and_generate_are_logged(): void
    {
        $superAdmin = $this->superAdminWithOwnAccount();
        $target = Account::factory()->create();
        // O departamento Fiscal já vem semeado com a Account.

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/support/accounts/{$target->getKey()}/enter")
            ->assertOk();

        $templateId = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/process-templates', [
                'name' => 'PGDAS',
                'steps' => [
                    ['title' => 'Apurar', 'department' => 'Fiscal', 'due_day' => 5, 'priority' => 'medium', 'order' => 1],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        Client::factory()->company()->create([
            'account_id' => $target->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/process-templates/{$templateId}", ['description' => 'Atualizado pelo suporte'])
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/process-templates/{$templateId}/generate", ['reference_month' => '2026-03'])
            ->assertCreated();

        $processId = ProcessTemplate::withoutGlobalScopes()->find($templateId)->processes()->sole()->getKey();
        $taskId = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/processes/{$processId}")
            ->assertOk()
            ->json('data.tasks.0.id');

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/processes/{$processId}", ['status' => 'in_progress'])
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/tasks/{$taskId}", ['status' => 'doing'])
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/processes/{$processId}")
            ->assertNoContent();

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/process-templates/{$templateId}")
            ->assertNoContent();

        foreach ([
            ['resource' => 'process_templates', 'action' => 'create'],
            ['resource' => 'process_templates', 'action' => 'update'],
            ['resource' => 'process_templates', 'action' => 'generate'],
            ['resource' => 'processes', 'action' => 'update'],
            ['resource' => 'tasks', 'action' => 'update'],
            ['resource' => 'processes', 'action' => 'delete'],
            ['resource' => 'process_templates', 'action' => 'delete'],
        ] as $expected) {
            $this->assertDatabaseHas('support_access_logs', [
                'super_admin_user_id' => $superAdmin->getKey(),
                'account_id' => $target->getKey(),
                'action' => $expected['action'],
            ]);
            $this->assertTrue(
                SupportAccessLog::query()
                    ->where('super_admin_user_id', $superAdmin->getKey())
                    ->where('account_id', $target->getKey())
                    ->where('action', $expected['action'])
                    ->where('metadata->resource', $expected['resource'])
                    ->exists(),
                "Faltou log {$expected['resource']}.{$expected['action']} em support-mode."
            );
        }
    }

    public function test_member_writes_on_work_do_not_log(): void
    {
        $account = Account::factory()->create();
        // O departamento Fiscal já vem semeado com a Account.
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum');

        $templateId = $this->postJson('/api/process-templates', [
            'name' => 'PGDAS',
            'steps' => [
                ['title' => 'Apurar', 'department' => 'Fiscal', 'due_day' => 5, 'priority' => 'medium', 'order' => 1],
            ],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/process-templates/{$templateId}", ['description' => 'Edição membro'])->assertOk();

        $this->assertSame(0, SupportAccessLog::count());
    }

    private function superAdminWithOwnAccount(): User
    {
        $superAdmin = User::factory()->create();
        $superAdmin->forceFill(['is_super_admin' => true])->save();
        $home = Account::factory()->create();

        AccountUser::create([
            'account_id' => $home->getKey(),
            'user_id' => $superAdmin->getKey(),
            'role' => 'admin',
        ]);

        $superAdmin->forceFill(['current_account_id' => $home->getKey()])->save();

        return $superAdmin->refresh();
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
