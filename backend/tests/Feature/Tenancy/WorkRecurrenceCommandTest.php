<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\BusinessTestCase;

class WorkRecurrenceCommandTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_failed_monthly_batch_rolls_back_all_clients_and_can_be_retried(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $template = ProcessTemplate::factory()->create(['account_id' => $account->getKey(), 'regimes' => [], 'is_active' => true]);
        $template->steps()->create(['title' => 'Transmit', 'due_day' => 20, 'priority' => 'medium', 'order' => 1]);
        Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Alpha', 'status' => 'active']);
        $second = Client::factory()->individual()->create(['account_id' => $account->getKey(), 'name' => 'Beta', 'status' => 'active']);
        $failSecond = true;
        Process::creating(function (Process $process) use ($second, &$failSecond): void {
            if ($failSecond && $process->client_id === $second->getKey()) {
                throw new \RuntimeException('batch interrupted');
            }
        });

        try {
            Artisan::call('work:generate-recurrences', ['--month' => '2026-03']);
            $this->fail('Expected interruption on the second client.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('batch interrupted', $exception->getMessage());
        }

        $this->assertDatabaseCount('processes', 0);
        $this->assertDatabaseCount('tasks', 0);
        $failSecond = false;
        $this->artisan('work:generate-recurrences', ['--month' => '2026-03'])->assertSuccessful();
        $this->assertDatabaseCount('processes', 2);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_command_generates_for_templates_with_due_generate_day_and_is_idempotent(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $member = $this->memberOf($account, 'admin');
        $this->actingAs($member, 'web');

        $this->travelTo(Carbon::create(2026, 3, 15, 12));

        $due = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Vencido',
            'generate_day' => 10,
            'due_day' => 20,
            'is_active' => true,
            'regimes' => null,
        ]);
        $due->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Etapa', 'department' => 'Fiscal',
            'due_day' => 20, 'priority' => 'medium', 'order' => 1,
        ]);
        Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $future = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Futuro',
            'generate_day' => 20,
            'due_day' => 25,
            'is_active' => true,
            'regimes' => null,
        ]);
        $future->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Etapa', 'department' => 'Fiscal',
            'due_day' => 25, 'priority' => 'medium', 'order' => 1,
        ]);

        $inactive = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Inativo',
            'generate_day' => 1,
            'due_day' => 10,
            'is_active' => false,
            'regimes' => null,
        ]);
        $inactive->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Etapa', 'department' => 'Fiscal',
            'due_day' => 10, 'priority' => 'medium', 'order' => 1,
        ]);

        $this->artisan('work:generate-recurrences')
            ->expectsOutputToContain('Concluído: 1 processo(s) em 2026-03.')
            ->assertSuccessful();

        $this->assertDatabaseCount('processes', 1);
        $this->assertDatabaseHas('processes', [
            'account_id' => $account->getKey(),
            'template_id' => $due->getKey(),
            'reference_month' => '2026-03-01 00:00:00',
        ]);

        $this->artisan('work:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('processes', 1);
    }

    public function test_command_accepts_explicit_month_option(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $this->actingAs($this->memberOf($account, 'admin'), 'web');

        $this->travelTo(Carbon::create(2026, 3, 15, 12));

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Retroativo',
            'generate_day' => 25,
            'due_day' => 20,
            'is_active' => true,
            'regimes' => null,
        ]);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Etapa', 'department' => 'Fiscal',
            'due_day' => 20, 'priority' => 'medium', 'order' => 1,
        ]);
        Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $this->artisan('work:generate-recurrences', ['--month' => '2026-02'])
            ->expectsOutputToContain('Concluído: 1 processo(s) em 2026-02.')
            ->assertSuccessful();

        $this->assertDatabaseHas('processes', [
            'account_id' => $account->getKey(),
            'template_id' => $template->getKey(),
            'reference_month' => '2026-02-01 00:00:00',
        ]);
    }

    public function test_command_rejects_invalid_month_option(): void
    {
        $this->travelTo(Carbon::create(2026, 3, 15, 12));

        $this->artisan('work:generate-recurrences', ['--month' => '2026-13'])
            ->expectsOutputToContain('Mês inválido')
            ->assertFailed();

        $this->assertDatabaseCount('processes', 0);
    }

    public function test_due_day_31_is_capped_to_last_day_of_february(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $this->actingAs($this->memberOf($account, 'admin'), 'web');

        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'Fevereiro',
            'generate_day' => 1,
            'due_day' => 31,
            'is_active' => true,
            'regimes' => null,
        ]);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Fechar', 'department' => 'Fiscal',
            'due_day' => 31, 'priority' => 'high', 'order' => 1,
        ]);
        Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $this->artisan('work:generate-recurrences', ['--month' => '2026-02'])->assertSuccessful();

        $process = Process::query()->firstOrFail();
        $this->assertSame('2026-02-28', $process->due_on->toDateString());
        $this->assertSame('2026-02-28', $process->tasks()->sole()->due_on->toDateString());
    }

    public function test_schedule_registers_daily_recurrence_generation(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'work:generate-recurrences'));

        $this->assertNotEmpty($events, 'work:generate-recurrences deve estar agendado.');
        $this->assertTrue(
            $events->every(fn ($event): bool => $event->getExpression() === '0 0 * * *'),
            'work:generate-recurrences deve rodar daily.'
        );
    }

    public function test_command_generates_in_february_leap_year_cap(): void
    {
        $this->assertSame('2026-02', Carbon::create(2026, 2, 10)->format('Y-m'));
        $this->assertSame(TaxRegime::SimpleNational->value, TaxRegime::SimpleNational->value);
        $this->assertSame(28, Carbon::create(2026, 2, 1)->daysInMonth);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
