<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SerproMonitoring;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

final class SerproMailboxRecovery
{
    public const STEP = 'serpro.mailbox.read';

    public function __construct(private SerproObligationCatalog $catalogo, private ExternalEffectJournal $journal) {}

    /** @return array{registro: SerproMonitoring, cliente: Client, hash: string} */
    public function resource(int $accountId, string $slug, int $clientId, int $isn): array
    {
        abort_unless((int) request()->user()?->getAuthIdentifier() >= 1, 401);
        abort_unless((int) CurrentAccount::fromRequest()->account->getKey() === $accountId, 404);
        Gate::authorize('readMessage', SerproMonitoring::class);
        $obrigacao = $this->catalogo->get($slug);
        abort_if($obrigacao === null || ! str_starts_with((string) $obrigacao['service'], 'CAIXAPOSTAL'), 404);
        $registro = SerproMonitoring::query()->withoutGlobalScope('account')
            ->where('account_id', $accountId)->where('client_id', $clientId)->where('obligation', $slug)->first();
        abort_unless(collect($registro?->messages ?? [])->contains(fn (array $stub): bool => (int) ($stub['id'] ?? 0) === $isn), 404);
        $cliente = Client::query()->withoutGlobalScope('account')->where('account_id', $accountId)->find($clientId);
        abort_if($cliente === null || $cliente->tax_id === null, 404);

        return ['registro' => $registro, 'cliente' => $cliente, 'hash' => ExternalEffectJournal::resourceHash($accountId, 'serpro.mailbox', [
            'provider' => 'CAIXAPOSTAL', 'client_id' => $clientId, 'isn' => $isn,
            'tax_id_fingerprint' => hash('sha256', (string) $cliente->tax_id),
        ])];
    }

    /** @return array<string, mixed>|null */
    public function confirmed(int $accountId, string $hash): ?array
    {
        $confirmed = null;
        foreach ($this->journal->forResource($accountId, $hash) as $effect) {
            if ($effect['step'] !== self::STEP) {
                continue;
            }
            if ($effect['state'] === 'uncertain') {
                $this->conflict('uncertain');
            }
            if ($effect['state'] === 'confirmed' && $effect['receipt'] === 'applied') {
                $confirmed = $effect;
            }
        }

        return $confirmed;
    }

    /** @return array<string, mixed> */
    public function reconcile(int $accountId, string $slug, int $clientId, int $isn): array
    {
        return resolve(CurrentTenant::class)->run($accountId, function () use ($accountId, $slug, $clientId, $isn): array {
            $resource = $this->resource($accountId, $slug, $clientId, $isn);
            $effect = $this->confirmed($accountId, $resource['hash']);
            if ($effect === null) {
                throw new HttpResponseException(response()->json([
                    'code' => 'operation_not_confirmed', 'effect_status' => 'unknown',
                    'message' => 'Não há confirmação disponível para reconciliar. Nenhuma nova consulta foi enviada.',
                ], 409, ['Cache-Control' => 'no-store']));
            }
            $dates = array_intersect_key($effect['payload'], array_flip(['lida_em', 'ciencia_em', 'prazo_limite']));
            $this->apply($resource['registro'], $isn, $dates);

            return ['id' => $isn, 'effect_status' => 'confirmed', 'body_available' => false,
                'lida_em' => $dates['lida_em'] ?? null, 'ciencia_em' => $dates['ciencia_em'] ?? null,
                'prazo_limite' => $dates['prazo_limite'] ?? null,
                'notice' => 'A ciência foi confirmada e os dados locais foram reconciliados. O corpo da mensagem não foi recuperado.',
            ];
        });
    }

    /** @param array<string, mixed> $dates */
    public function apply(SerproMonitoring $registro, int $isn, array $dates): void
    {
        $registro->forceFill(['messages' => collect($registro->messages)->map(function (array $stub) use ($isn, $dates): array {
            if ((int) ($stub['id'] ?? 0) !== $isn) {
                return $stub;
            }

            return [...$stub, ...array_intersect_key($dates, array_flip(['lida_em', 'ciencia_em', 'prazo_limite'])), 'unread' => false];
        })->values()->all()])->save();
    }

    public function conflict(string $status): never
    {
        $confirmed = $status === 'confirmed';
        throw new HttpResponseException(response()->json([
            'code' => $confirmed ? 'operation_confirmed' : 'operation_uncertain',
            'effect_status' => $status, 'body_available' => false,
            'message' => $confirmed
                ? 'A ciência já foi confirmada. Reconcilie os dados locais sem consultar o provedor novamente.'
                : 'Não foi possível confirmar o resultado da ciência. Não repita a consulta; a operação exige verificação.',
        ], 409, ['Cache-Control' => 'no-store']));
    }
}
