<?php

namespace App\Services;

use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproMonitoring;
use App\Tenant\CurrentTenant;

/**
 * PDFs do PGDAS-D sob demanda: guia (DAS), recibo e declaração.
 *
 * A listagem já traz períodos sincronizados; este serviço só entra quando o
 * Membro pede um arquivo. Nada de base64 na linha de monitoramento.
 */
final class SerproPgdasDocumentReader
{
    private const SLUG = 'declaracoes/pgdas';

    private const SISTEMA = 'PGDASD';

    public function __construct(
        private readonly SerproObligationCatalog $catalogo,
        private readonly SerproTermManager $termos,
        private readonly SerproClient $client,
        private readonly SerproCallRecorder $recorder,
    ) {}

    /**
     * @return array{filename: string, bytes: string, content_type: string}
     *
     * @throws SerproException
     */
    public function download(int $accountId, int $clientId, string $period, string $artifact): array
    {
        return resolve(CurrentTenant::class)->run($accountId, function () use ($accountId, $clientId, $period, $artifact) {
            abort_unless(in_array($artifact, ['guia', 'recibo', 'declaracao'], true), 404);
            abort_unless($this->catalogo->get(self::SLUG) !== null, 404);

            $linha = $this->periodoSincronizado($accountId, $clientId, $period);

            return match ($artifact) {
                'guia' => $this->guia($accountId, $clientId, $linha),
                'recibo' => $this->declaracaoOuRecibo($accountId, $clientId, $linha, 'recibo'),
                'declaracao' => $this->declaracaoOuRecibo($accountId, $clientId, $linha, 'declaracao'),
            };

        });
    }

    /**
     * @return array<string, mixed>
     */
    private function periodoSincronizado(int $accountId, int $clientId, string $period): array
    {
        $registro = SerproMonitoring::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('obligation', self::SLUG)
            ->first();

        abort_if($registro === null, 404);

        $periodos = collect($registro->periods ?? []);
        $linha = $periodos->first(fn (array $item): bool => (string) ($item['period'] ?? '') === $period);

        abort_if($linha === null, 404);

        return $linha;
    }

    /**
     * @param  array<string, mixed>  $linha
     * @return array{filename: string, bytes: string, content_type: string}
     */
    private function guia(int $accountId, int $clientId, array $linha): array
    {
        $numeroDas = isset($linha['slip_number']) ? (string) $linha['slip_number'] : '';

        if ($numeroDas !== '') {
            $resultado = $this->consultar(
                $accountId,
                $clientId,
                'CONSEXTRATO16',
                ['numeroDas' => $numeroDas],
            );

            return $this->pdfDeResposta($resultado, "das-{$numeroDas}.pdf", ['pdf', 'extrato', 'PDF']);
        }

        abort_unless($linha['declared_at'] ?? null, 404, 'Não há declaração transmitida para gerar a guia.');

        $pa = $this->periodoApuracao((string) $linha['period']);
        $resultado = $this->consultar(
            $accountId,
            $clientId,
            'GERARDAS12',
            ['periodoApuracao' => $pa],
        );

        return $this->pdfDeResposta($resultado, "das-{$pa}.pdf", ['pdf', 'PDF']);
    }

    /**
     * @param  array<string, mixed>  $linha
     * @return array{filename: string, bytes: string, content_type: string}
     */
    private function declaracaoOuRecibo(int $accountId, int $clientId, array $linha, string $parte): array
    {
        $numero = isset($linha['declaration_number']) ? (string) $linha['declaration_number'] : '';
        abort_if($numero === '', 404, 'Não há número de declaração sincronizado para este período.');

        $resultado = $this->consultar(
            $accountId,
            $clientId,
            'CONSDECREC15',
            ['numeroDeclaracao' => $numero],
        );

        $dados = $this->dadosDecodificados($resultado->dados());
        $no = is_array($dados) ? ($dados[$parte] ?? null) : null;

        return $this->pdfDeNo($no, "{$parte}-{$numero}.pdf");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function consultar(int $accountId, int $clientId, string $idServico, array $payload): SerproResult
    {
        $cliente = Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->find($clientId);

        abort_if($cliente === null || $cliente->tax_id === null, 404);

        $token = $this->termos->validToken($accountId);
        abort_if($token === null, 409, 'O termo de autorização do escritório não está vigente.');

        $certificado = AccountCertificate::currentFor($accountId);
        abort_if($certificado === null, 409, 'O escritório não tem e-CNPJ cadastrado.');

        return $this->recorder->record(
            null,
            $accountId,
            $clientId,
            self::SISTEMA,
            $idServico,
            fn (): SerproResult => $this->client->call(
                self::SISTEMA,
                $idServico,
                $payload,
                (string) $certificado->document,
                (string) $cliente->tax_id,
                $token,
            ),
        );
    }

    private function periodoApuracao(string $period): string
    {
        $digits = preg_replace('/\D/', '', $period) ?? '';

        return strlen($digits) === 6 ? $digits : str_replace('-', '', $period);
    }

    private function dadosDecodificados(mixed $dados): mixed
    {
        if (is_string($dados) && $dados !== '') {
            $decoded = json_decode($dados, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $dados;
        }

        return $dados;
    }

    /**
     * @param  list<string>  $chaves
     * @return array{filename: string, bytes: string, content_type: string}
     */
    private function pdfDeResposta(SerproResult $resultado, string $filename, array $chaves): array
    {
        $dados = $this->dadosDecodificados($resultado->dados());

        if (is_array($dados)) {
            foreach ($chaves as $chave) {
                if (array_key_exists($chave, $dados)) {
                    return $this->pdfDeNo($dados[$chave], $filename);
                }
            }

            return $this->pdfDeNo($dados, $filename);
        }

        return $this->pdfDeNo($dados, $filename);
    }

    /**
     * @return array{filename: string, bytes: string, content_type: string}
     */
    private function pdfDeNo(mixed $no, string $filename): array
    {
        $base64 = null;

        if (is_string($no)) {
            $base64 = $no;
        } elseif (is_array($no)) {
            $base64 = $no['pdf'] ?? $no['PDF'] ?? $no['conteudo'] ?? $no['conteudoBase64'] ?? null;
            if (is_array($base64)) {
                $base64 = $base64['pdf'] ?? null;
            }
        }

        abort_if(! is_string($base64) || $base64 === '', 502, 'O provedor não devolveu o PDF solicitado.');

        $base64 = preg_replace('/\s+/', '', $base64) ?? '';
        $bytes = base64_decode($base64, true);
        abort_if($bytes === false || $bytes === '', 502, 'O PDF retornado pelo provedor está inválido.');

        $nome = is_array($no) && isset($no['nomeArquivo']) ? (string) $no['nomeArquivo'] : $filename;

        return [
            'filename' => $nome !== '' ? $nome : $filename,
            'bytes' => $bytes,
            'content_type' => 'application/pdf',
        ];
    }
}
