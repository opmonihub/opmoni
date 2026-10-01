<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\Department;
use App\Models\ProcessTemplate;
use App\Models\Tag;
use App\Models\User;
use App\Services\ProcessGenerationService;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_generated_month_is_frozen_after_template_changes(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum');

        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $contabil = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Contábil')
            ->sole();

        $tag = Tag::factory()->create(['account_id' => $account->getKey()]);
        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(),
            'name' => 'DCTF',
            'regimes' => [TaxRegime::SimpleNational->value],
            'due_day' => 20,
        ]);
        $template->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Apurar', 'department_id' => $fiscal->getKey(),
            'due_day' => 5, 'priority' => 'medium', 'order' => 1,
        ]);

        $kept = Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);
        $kept->tags()->attach($tag->getKey(), ['account_id' => $account->getKey()]);

        $march = Carbon::create(2026, 3, 1)->startOfDay();
        $generated = app(ProcessGenerationService::class)->generate($template->refresh(), $march);

        $this->assertCount(1, $generated);
        $process = $generated->first();
        $this->assertSame('2026-03-20', $process->due_on->toDateString());
        $this->assertSame(['Apurar'], $process->tasks()->ordered()->pluck('title')->all());

        $template->update(['regimes' => [TaxRegime::PresumedProfit->value], 'due_day' => 10]);
        $template->tags()->detach();
        $template->steps()->delete();
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Nova etapa', 'department_id' => $contabil->getKey(),
            'due_day' => 2, 'priority' => 'high', 'order' => 1,
        ]);
        $excluded = Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::PresumedProfit, 'status' => 'active',
        ]);

        $again = app(ProcessGenerationService::class)->generate($template->refresh(), $march);

        $this->assertCount(1, $again);
        $this->assertSame($process->getKey(), $again->first()->getKey());
        $this->assertDatabaseCount('processes', 1);
        $this->assertSame('2026-03-20', $again->first()->refresh()->due_on->toDateString());
        $this->assertSame(['Apurar'], $again->first()->tasks()->ordered()->pluck('title')->all());
        $this->assertSame($fiscal->getKey(), $again->first()->tasks()->sole()->department_id);

        $april = Carbon::create(2026, 4, 1)->startOfDay();
        $next = app(ProcessGenerationService::class)->generate($template->refresh(), $april);

        $this->assertCount(1, $next);
        $this->assertSame($excluded->getKey(), $next->first()->client_id);
        $this->assertSame('2026-04-10', $next->first()->due_on->toDateString());
        $this->assertSame(['Nova etapa'], $next->first()->tasks()->ordered()->pluck('title')->all());
    }

    public function test_removed_exception_does_not_delete_already_generated_process(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($this->memberOf($account, 'admin'), 'sanctum');

        $fiscal = Department::withoutGlobalScopes()
            ->where('account_id', $account->getKey())
            ->where('name', 'Fiscal')
            ->sole();
        $template = ProcessTemplate::factory()->create([
            'account_id' => $account->getKey(), 'name' => 'EFD', 'regimes' => null,
        ]);
        $template->steps()->create([
            'account_id' => $account->getKey(), 'title' => 'Etapa', 'department_id' => $fiscal->getKey(),
            'due_day' => 5, 'priority' => 'medium', 'order' => 1,
        ]);
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(), 'tax_regime' => TaxRegime::SimpleNational, 'status' => 'active',
        ]);

        $march = Carbon::create(2026, 3, 1)->startOfDay();
        app(ProcessGenerationService::class)->generate($template->refresh(), $march);
        $processId = $template->processes()->sole()->getKey();

        $template->exceptions()->create([
            'account_id' => $account->getKey(), 'client_id' => $client->getKey(), 'kind' => 'removed',
        ]);

        $again = app(ProcessGenerationService::class)->generate($template->refresh(), $march);

        $this->assertCount(1, $again);
        $this->assertSame($processId, $again->first()->getKey());
        $this->assertDatabaseHas('processes', ['id' => $processId, 'client_id' => $client->getKey()]);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
