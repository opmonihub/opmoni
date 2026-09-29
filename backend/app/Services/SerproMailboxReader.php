<?php

namespace App\Services;

use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproMonitoring;

/**
 * A leitura de uma mensagem da caixa postal, que é ciência da intimação (D19).
 *
 * Tudo o que pode recusar a leitura corre **antes** da chamada: obrigação sem
 * caixa postal, mensagem que não está na caixa sincronizada do cliente, termo
 * ou certificado ausente. A ordem importa porque a chamada é o ato jurídico, e
 * uma recusa depois dela não desfaz a ciência.
 *
 * O `isn` só é aceito se estiver entre os stubs que a sincronização gravou
 * para esse cliente nessa obrigação. O provedor identifica a mensagem pelo
 * `isn` dentro da caixa do contribuinte, e é essa conferência que impede um
 * `isn` digitado de registrar ciência numa mensagem que a tela nunca mostrou.
 */
final class SerproMailboxReader
{
    private const SISTEMA = 'CAIXAPOSTAL';

    private const SERVICO = 'MSGDETALHAMENTO62';

    public function __construct(
        private readonly SerproObligationCatalog $catalogo,
        private readonly SerproTermManager $termos,
        private readonly SerproClient $client,
        private readonly SerproCallRecorder $recorder,
        private readonly SerproMonitoringMapper $mapper,
    ) {}

    /**
     * @return array{id: int, codigo: ?string, assunto: string, corpo: string, lida_em: ?string, ciencia_em: ?string, prazo_limite: ?string}
     *
     * @throws SerproException
     */
    public function read(int $accountId, string $slug, int $clientId, int $isn): array
    {
        $obrigacao = $this->catalogo->get($slug);
        abort_if(
            $obrigacao === null || ! str_starts_with((string) $obrigacao['service'], self::SISTEMA),
            404,
        );

        $registro = SerproMonitoring::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('obligation', $slug)
            ->first();

        $stubs = collect($registro?->messages ?? []);
        abort_unless($stubs->contains(fn (array $stub): bool => (int) ($stub['id'] ?? 0) === $isn), 404);

        $cliente = Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->find($clientId);
        abort_if($cliente === null || $cliente->tax_id === null, 404);

        $token = $this->termos->validToken($accountId);
        abort_if($token === null, 409, 'O termo de autorização do escritório não está vigente.');

        $certificado = AccountCertificate::currentFor($accountId);
        abort_if($certificado === null, 409, 'O escritório não tem e-CNPJ cadastrado.');

        $resultado = $this->recorder->record(
            null,
            $accountId,
            $clientId,
            self::SISTEMA,
            self::SERVICO,
            fn (): SerproResult => $this->client->call(
                self::SISTEMA,
                self::SERVICO,
                // O exemplo do provedor manda o `isn` como string de dez dígitos.
                ['isn' => str_pad((string) $isn, 10, '0', STR_PAD_LEFT)],
                (string) $certificado->document,
                (string) $cliente->tax_id,
                $token,
            ),
        );

        $mensagem = $this->mapper->detalheMensagem($isn, $resultado->dados());

        $this->registrarLeitura($registro, $isn, $mensagem);

        return $mensagem;
    }

    /**
     * O stub passa a dizer o que o provedor registrou, para a listagem não
     * continuar mostrando como não lida uma mensagem cuja ciência já correu.
     *
     * @param  array{lida_em: ?string, ciencia_em: ?string, prazo_limite: ?string}  $mensagem
     */
    private function registrarLeitura(SerproMonitoring $registro, int $isn, array $mensagem): void
    {
        $registro->forceFill([
            'messages' => collect($registro->messages)
                ->map(fn (array $stub): array => (int) ($stub['id'] ?? 0) !== $isn ? $stub : [
                    ...$stub,
                    'lida_em' => $mensagem['lida_em'] ?? $stub['lida_em'] ?? now()->toDateTimeString(),
                    'ciencia_em' => $mensagem['ciencia_em'] ?? $stub['ciencia_em'] ?? null,
                    'prazo_limite' => $mensagem['prazo_limite'] ?? $stub['prazo_limite'] ?? null,
                    'unread' => false,
                ])
                ->values()
                ->all(),
        ])->save();
    }
}
