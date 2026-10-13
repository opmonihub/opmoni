<?php

namespace Database\Seeders;

use App\Enums\ClientPersonType;
use App\Enums\ClientStatus;
use App\Enums\SerproPowerOfAttorneyState;
use App\Enums\TaxRegime;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\Plan;
use App\Models\SerproClientAuthorization;
use App\Models\Tag;
use App\Services\BrazilianTaxId;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carteira sintética de desenvolvimento: 200 clientes com CPF/CNPJ válidos,
 * cobertura dos quatro estados de prazo (missing/valid/expiring/expired)
 * no certificado A1 e na procuração e-CAC, e tags de catálogo.
 *
 * Idempotente por conta: a segunda execução limpa apenas os dados marcados
 * com a tag `seed-dev` e recria a carteira — outros tenants não são tocados.
 *
 * Uso (requer uma conta existente — cria via onboarding ou register):
 *   php artisan dev:seed-clients --account=6
 *   php artisan dev:seed-clients --account=6 --count=50
 */
class DevClientPortfolioSeeder extends Seeder
{
    use WithoutModelEvents;

    private const MARKER_TAG = 'seed-dev';

    private const CATALOG_TAGS = [
        ['name' => 'Prioridade', 'color' => 'warning'],
        ['name' => 'Recorrente', 'color' => 'primary'],
        ['name' => 'Acompanhar', 'color' => 'info'],
        ['name' => 'Risco vencimento', 'color' => 'error'],
        ['name' => 'Em dia', 'color' => 'success'],
    ];

    public const CERT_SPLIT = ['valid' => 60, 'expiring' => 40, 'expired' => 40, 'missing' => 60];

    public const POA_SPLIT = ['valid' => 70, 'expiring' => 40, 'expired' => 30, 'missing' => 60];

    public function run(?int $account = null, int $count = 200): void
    {
        $tenant = $account !== null
            ? Account::query()->findOrFail($account)
            : Account::query()->orderBy('id')->firstOrFail();

        $this->ensureUnlimitedSubscription($tenant);

        $this->clearPreviousSeed($tenant->getKey());

        $faker = fake('pt_BR');
        $taxIds = new BrazilianTaxId;

        $certPlan = $this->spread($count, self::CERT_SPLIT);
        $poaPlan = $this->spread($count, self::POA_SPLIT);

        Model::unguarded(function () use ($tenant, $count, $faker, $taxIds, $certPlan, $poaPlan): void {
            $marker = $this->seedTags($tenant);
            $catalog = Tag::query()->where('account_id', $tenant->getKey())->whereIn('name', array_column(self::CATALOG_TAGS, 'name'))->get();

            $statuses = [ClientStatus::Active->value, ClientStatus::Active->value, ClientStatus::Active->value,
                ClientStatus::Active->value, ClientStatus::Active->value, ClientStatus::Active->value,
                ClientStatus::Inactive->value];

            for ($index = 0; $index < $count; $index++) {
                $personType = $index % 5 === 4 ? ClientPersonType::Individual : ClientPersonType::Company;

                $client = $this->makeClient($tenant->getKey(), $faker, $taxIds, $personType, $statuses, $index);

                $this->makeCertificate($tenant->getKey(), $client, $certPlan[$index]);
                $this->makePowerOfAttorney($tenant->getKey(), $client, $poaPlan[$index]);
                $this->attachTags($tenant->getKey(), $client, $catalog, $marker, $index);
            }
        });

        $fresh = Client::withoutGlobalScopes()->where('account_id', $tenant->getKey())->count();
        $this->command?->info("Carteira dev: {$fresh} clientes na conta {$tenant->getKey()} ({$tenant->name}).");
    }

    /**
     * Distribui as posições 0..$count-1 entre os estados e embaralha,
     * garantindo que cada estado apareça (proporcional ao split).
     *
     * @param  array<string, int>  $split
     * @return list<string>
     */
    private function spread(int $count, array $split): array
    {
        $total = array_sum($split);
        $plan = [];

        foreach ($split as $state => $weight) {
            $take = (int) round($count * $weight / $total);
            $plan = array_merge($plan, array_fill(0, $take, $state));
        }

        while (count($plan) < $count) {
            $plan[] = array_key_first($split);
        }

        $plan = array_slice($plan, 0, $count);
        shuffle($plan);

        return array_values($plan);
    }

    private function ensureUnlimitedSubscription(Account $tenant): void
    {
        $this->call(PlanSeeder::class);

        $plan = Plan::query()->where('slug', 'empresarial')->firstOrFail();

        $tenant->subscription()->updateOrCreate(
            [],
            ['plan_id' => $plan->getKey(), 'status' => 'active']
        );
    }

    private function clearPreviousSeed(int $accountId): void
    {
        $markerId = Tag::withoutGlobalScopes()->where('account_id', $accountId)->where('name', self::MARKER_TAG)->value('id');

        if ($markerId === null) {
            return;
        }

        $clientIds = DB::table('client_tag')
            ->where('account_id', $accountId)
            ->where('tag_id', $markerId)
            ->pluck('client_id')
            ->all();

        if ($clientIds !== []) {
            DB::table('client_tag')->whereIn('client_id', $clientIds)->delete();
            SerproClientAuthorization::withoutGlobalScopes()->whereIn('client_id', $clientIds)->delete();
            ClientCertificate::withoutGlobalScopes()->whereIn('client_id', $clientIds)->forceDelete();
            Client::withoutGlobalScopes()->whereIn('id', $clientIds)->forceDelete();
        }

        Tag::withoutGlobalScopes()->where('account_id', $accountId)->where('name', self::MARKER_TAG)->delete();
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function makeClient(int $accountId, mixed $faker, BrazilianTaxId $taxIds, ClientPersonType $personType, array $statuses, int $index): Client
    {
        $taxId = $personType === ClientPersonType::Company
            ? $this->uniqueTaxId($taxIds, 14, fn (): string => preg_replace('/\D/', '', $faker->unique()->cnpj(false)))
            : $this->uniqueTaxId($taxIds, 11, fn (): string => preg_replace('/\D/', '', $faker->unique()->cpf(false)));

        $isCompany = $personType === ClientPersonType::Company;

        $regimes = $isCompany
            ? [TaxRegime::SimpleNational, TaxRegime::PresumedProfit, TaxRegime::ActualProfit, TaxRegime::Mei, TaxRegime::Other]
            : [TaxRegime::NotApplicable];

        $address = $isCompany ? [] : [
            'street_type' => $faker->randomElement(['RUA', 'AVENIDA', 'TRAVESSA', 'ALAMEDA']),
            'street' => $faker->streetName(),
            'address_number' => (string) $faker->buildingNumber(),
            'address_complement' => $faker->optional(0.3)->secondaryAddress(),
            'district' => $faker->citySuffix() !== '' ? $faker->words(2, true) : $faker->word(),
            'postal_code' => preg_replace('/\D/', '', $faker->postcode()),
            'city' => $faker->city(),
            'state' => $faker->stateAbbr(),
        ];

        return Client::withoutGlobalScopes()->create(array_merge([
            'account_id' => $accountId,
            'person_type' => $personType->value,
            'tax_id' => $taxId,
            'name' => $isCompany ? $faker->company() : $faker->name(),
            'trade_name' => $isCompany ? $faker->optional(0.6)->company() : null,
            'status' => $statuses[array_rand($statuses)],
            'tax_regime' => $faker->randomElement($regimes)->value,
            'registration_status' => $isCompany ? $faker->randomElement(['Ativa', 'Ativa', 'Ativa', 'Suspensa', 'Baixada']) : null,
            'registration_status_date' => $isCompany ? $faker->dateTimeBetween('-10 years', '-1 year')->format('Y-m-d') : null,
            'opened_at' => $faker->dateTimeBetween('-20 years', '-1 year')->format('Y-m-d'),
            'company_size' => $isCompany ? $faker->randomElement(['Micro Empresa', 'Empresa de Pequeno Porte', 'Demais']) : null,
            'legal_nature' => $isCompany ? $faker->randomElement(['Empresário (Individual)', 'Sociedade Empresária Limitada', 'Sociedade Simples', 'MEI']) : null,
            'primary_activity_code' => $faker->optional(0.9)->numerify('#######'),
            'primary_activity_description' => $faker->optional(0.9)->words(4, true),
            'email' => $faker->optional(0.8)->safeEmail(),
            'phone' => preg_replace('/\D/', '', $faker->phoneNumber()),
            'source_updated_at' => now()->subDays($faker->numberBetween(0, 30)),
            'looked_up_at' => $isCompany ? now()->subDays($faker->numberBetween(0, 30)) : null,
        ], $address));
    }

    /** @param callable(): string $generate */
    private function uniqueTaxId(BrazilianTaxId $taxIds, int $length, callable $generate): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $generate();
            $valid = $length === 14 ? $taxIds->isValidCnpj($candidate) : $taxIds->isValidCpf($candidate);

            if ($valid && ! Client::withoutGlobalScopes()->where('tax_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Não foi possível gerar um CPF/CNPJ único e válido para o seed.');
    }

    private function makeCertificate(int $accountId, Client $client, string $state): void
    {
        $validUntil = match ($state) {
            'valid' => now()->addDays(fake()->numberBetween(45, 300)),
            'expiring' => now()->addDays(fake()->numberBetween(0, 30)),
            'expired' => now()->subDays(fake()->numberBetween(1, 200)),
            default => null,
        };

        if ($validUntil === null) {
            return;
        }

        ClientCertificate::withoutGlobalScopes()->create([
            'account_id' => $accountId,
            'client_id' => $client->getKey(),
            'subject' => "CN={$client->name}:{$client->tax_id}",
            'serial_number' => strtoupper(fake()->unique()->bothify('##########??')),
            'valid_from' => (clone $validUntil)->subYear(),
            'valid_until' => $validUntil,
            'original_filename' => fake()->slug().'.pfx',
            'storage_path' => null,
            'sha256' => hash('sha256', $client->getKey().$client->tax_id.'cert'),
            'replaced_at' => null,
            'removed_at' => null,
        ]);
    }

    private function makePowerOfAttorney(int $accountId, Client $client, string $state): void
    {
        $expiresAt = match ($state) {
            'valid' => today()->addDays(fake()->numberBetween(45, 400)),
            'expiring' => today()->addDays(fake()->numberBetween(0, 30)),
            'expired' => today()->subDays(fake()->numberBetween(1, 200)),
            default => null,
        };

        if ($expiresAt === null) {
            return;
        }

        SerproClientAuthorization::withoutGlobalScopes()->create([
            'account_id' => $accountId,
            'client_id' => $client->getKey(),
            'family' => '00006',
            'code' => '00006',
            'state' => SerproPowerOfAttorneyState::Established,
            'expires_on' => $expiresAt,
            'verified_at' => now()->subDays(fake()->numberBetween(1, 10)),
        ]);
    }

    private function seedTags(Account $tenant): Tag
    {
        foreach (self::CATALOG_TAGS as $tag) {
            Tag::withoutGlobalScopes()->firstOrCreate(
                ['name' => $tag['name'], 'account_id' => $tenant->getKey()],
                ['color' => $tag['color']]
            );
        }

        return Tag::withoutGlobalScopes()->firstOrCreate(
            ['name' => self::MARKER_TAG, 'account_id' => $tenant->getKey()],
            ['color' => 'neutral']
        );
    }

    /** @param Collection<int, Tag> $catalog */
    private function attachTags(int $accountId, Client $client, mixed $catalog, Tag $marker, int $index): void
    {
        $tagIds = [$marker->getKey()];

        if (! $catalog->isEmpty() && $index % 3 !== 2) {
            $tagIds[] = $catalog->random()->getKey();

            if ($index % 4 === 0) {
                $tagIds[] = $catalog->random()->getKey();
            }
        }

        $rows = array_map(fn (int $tagId): array => [
            'account_id' => $accountId,
            'client_id' => $client->getKey(),
            'tag_id' => $tagId,
        ], array_unique($tagIds));

        DB::table('client_tag')->insert($rows);
    }
}
