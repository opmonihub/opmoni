<?php

namespace App\Erp\Fiscal;

use App\Erp\BrazilianStateCode;
use App\Erp\Finance\ErpDecimal;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use InvalidArgumentException;

/**
 * Canonical fiscal payload and access-key builder ported from sistema NFe/NFCe/CTe/MDFe services.
 */
final class ErpFiscalPayloadBuilder
{
    /** @var array<string, string> */
    private const MODELS = [
        'nfe' => '55',
        'nfce' => '65',
        'cte' => '57',
        'mdfe' => '58',
    ];

    /**
     * @param  array{
     *     uf: string,
     *     issued_at?: string,
     *     cnpj: string,
     *     model: string,
     *     series: int,
     *     number: int,
     *     tp_emis?: int,
     *     cnf?: int|string|null
     * }  $input
     */
    public function buildAccessKey(array $input): string
    {
        $model = $this->normalizeModel($input['model'] ?? '');
        $uf = BrazilianStateCode::ibgeFromUf($input['uf'] ?? '');
        if ($uf === null) {
            throw new InvalidArgumentException('UF inválida para montagem da chave.');
        }

        $issuedAt = $input['issued_at'] ?? now()->toIso8601String();
        $timestamp = strtotime($issuedAt);
        if ($timestamp === false) {
            throw new InvalidArgumentException('Data de emissão inválida.');
        }

        $cnpj = $this->digits($input['cnpj'] ?? '', 14);
        $series = max(1, (int) ($input['series'] ?? 1));
        $number = max(1, (int) ($input['number'] ?? 1));
        $tpEmis = (int) ($input['tp_emis'] ?? 1);
        $cnf = $input['cnf'] ?? random_int(11111111, 99999999);
        $cnfDigits = str_pad(substr(preg_replace('/\D+/', '', (string) $cnf) ?? '', -8), 8, '0', STR_PAD_LEFT);

        $base = sprintf(
            '%02d%02d%02d%s%02d%03d%09d%01d%08s',
            (int) $uf,
            (int) date('y', $timestamp),
            (int) date('m', $timestamp),
            $cnpj,
            (int) $model,
            $series,
            $number,
            $tpEmis,
            $cnfDigits,
        );

        if (strlen($base) !== 43) {
            throw new InvalidArgumentException('Base da chave de acesso incompleta.');
        }

        return $base.$this->mod11Digit($base);
    }

    public function validatesAccessKey(string $chave): bool
    {
        return FiscalXmlMetadata::isValidChave($chave);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildDocumentPayload(string $model, array $context): array
    {
        $normalized = $this->normalizeModel($model);
        $contingency = $this->resolveContingency(
            $normalized,
            (bool) ($context['contingency_active'] ?? false),
            isset($context['contingency_status']) ? (string) $context['contingency_status'] : null,
        );

        return match ($normalized) {
            '55' => $this->buildNfePayload($context, $contingency),
            '65' => $this->buildNfcePayload($context, $contingency),
            '57' => $this->buildCtePayload($context, $contingency),
            '58' => $this->buildMdfePayload($context, $contingency),
            default => throw new InvalidArgumentException('Modelo fiscal não suportado.'),
        };
    }

    /**
     * @param  array{
     *     model: string,
     *     series: int,
     *     number_from: int,
     *     number_to: int,
     *     justification: string
     * }  $input
     * @return list<string>
     */
    public function validateInvalidation(array $input): array
    {
        $errors = [];
        $series = (int) ($input['series'] ?? 0);
        $from = (int) ($input['number_from'] ?? 0);
        $to = (int) ($input['number_to'] ?? 0);
        $justification = trim((string) ($input['justification'] ?? ''));

        try {
            $this->normalizeModel((string) ($input['model'] ?? ''));
        } catch (InvalidArgumentException) {
            $errors[] = 'Modelo fiscal inválido para inutilização.';
        }

        if ($series <= 0) {
            $errors[] = 'Série zerada ou inválida.';
        }
        if ($from <= 0 || $to <= 0 || $from > $to) {
            $errors[] = 'Faixa de numeração inválida.';
        }
        if (strlen($justification) < 15) {
            $errors[] = 'Justificativa deve ter ao menos 15 caracteres.';
        }

        return $errors;
    }

    /**
     * @return array{tp_emis: int, claims_provider_authorization: bool, contingency_mode: bool}
     */
    public function resolveContingency(string $model, bool $active, ?string $statusRetorno = null): array
    {
        $normalized = $this->normalizeModel($model);
        if (! $active) {
            return [
                'tp_emis' => 1,
                'claims_provider_authorization' => true,
                'contingency_mode' => false,
            ];
        }

        if (! in_array($normalized, ['55', '65'], true)) {
            throw new InvalidArgumentException('Contingência não suportada para este modelo.');
        }

        $tpEmis = $this->inferContingencyTpEmis($statusRetorno, $normalized);

        return [
            'tp_emis' => $tpEmis,
            'claims_provider_authorization' => false,
            'contingency_mode' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{tp_emis: int, claims_provider_authorization: bool, contingency_mode: bool}  $contingency
     * @return array<string, mixed>
     */
    private function buildNfePayload(array $context, array $contingency): array
    {
        $accessKey = $context['access_key'] ?? $this->buildAccessKey([
            'uf' => (string) ($context['uf'] ?? 'SP'),
            'cnpj' => (string) ($context['cnpj'] ?? ''),
            'model' => '55',
            'series' => (int) ($context['series'] ?? 1),
            'number' => (int) ($context['number'] ?? 1),
            'tp_emis' => $contingency['tp_emis'],
            'cnf' => $context['cnf'] ?? null,
            'issued_at' => $context['issued_at'] ?? null,
        ]);

        return [
            'model' => '55',
            'layout_version' => '4.00',
            'access_key' => $accessKey,
            'ide' => [
                'cUF' => BrazilianStateCode::ibgeFromUf((string) ($context['uf'] ?? 'SP')),
                'natOp' => (string) ($context['natureza_operacao'] ?? 'Venda'),
                'mod' => 55,
                'serie' => (int) ($context['series'] ?? 1),
                'nNF' => (int) ($context['number'] ?? 1),
                'tpNF' => (int) ($context['tp_nf'] ?? 1),
                'tpEmis' => $contingency['tp_emis'],
                'tpAmb' => (int) ($context['environment'] ?? 2),
                'finNFe' => (int) ($context['fin_nfe'] ?? 1),
                'indFinal' => (int) ($context['ind_final'] ?? 0),
                'indPres' => (int) ($context['ind_pres'] ?? 1),
                'procEmi' => '0',
                'verProc' => 'opmoni-erp',
            ],
            'emit' => [
                'CNPJ' => $this->digits((string) ($context['cnpj'] ?? ''), 14),
                'xNome' => (string) ($context['razao_social'] ?? ''),
                'IE' => preg_replace('/\D+/', '', (string) ($context['ie'] ?? '')) ?: null,
            ],
            'totals' => [
                'vProd' => ErpDecimal::parse((string) ($context['total_produtos'] ?? '0')),
                'vNF' => ErpDecimal::parse((string) ($context['total_nf'] ?? $context['total_produtos'] ?? '0')),
            ],
            'contingency' => $contingency,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{tp_emis: int, claims_provider_authorization: bool, contingency_mode: bool}  $contingency
     * @return array<string, mixed>
     */
    private function buildNfcePayload(array $context, array $contingency): array
    {
        $payload = $this->buildNfePayload($context, $contingency);
        $payload['model'] = '65';
        $payload['ide']['mod'] = 65;
        $payload['ide']['tpImp'] = 4;
        $payload['ide']['indFinal'] = 1;
        $payload['ide']['indPres'] = 1;
        $payload['access_key'] = $context['access_key'] ?? $this->buildAccessKey([
            'uf' => (string) ($context['uf'] ?? 'SP'),
            'cnpj' => (string) ($context['cnpj'] ?? ''),
            'model' => '65',
            'series' => (int) ($context['series'] ?? 1),
            'number' => (int) ($context['number'] ?? 1),
            'tp_emis' => $contingency['tp_emis'],
            'cnf' => $context['cnf'] ?? null,
            'issued_at' => $context['issued_at'] ?? null,
        ]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{tp_emis: int, claims_provider_authorization: bool, contingency_mode: bool}  $contingency
     * @return array<string, mixed>
     */
    private function buildCtePayload(array $context, array $contingency): array
    {
        $accessKey = $context['access_key'] ?? $this->buildAccessKey([
            'uf' => (string) ($context['uf'] ?? 'SP'),
            'cnpj' => (string) ($context['cnpj'] ?? ''),
            'model' => '57',
            'series' => (int) ($context['series'] ?? 1),
            'number' => (int) ($context['number'] ?? 1),
            'tp_emis' => $contingency['tp_emis'],
            'cnf' => $context['cnf'] ?? null,
            'issued_at' => $context['issued_at'] ?? null,
        ]);

        return [
            'model' => '57',
            'layout_version' => '4.00',
            'access_key' => $accessKey,
            'ide' => [
                'cUF' => BrazilianStateCode::ibgeFromUf((string) ($context['uf'] ?? 'SP')),
                'mod' => '57',
                'serie' => (int) ($context['series'] ?? 1),
                'nCT' => (int) ($context['number'] ?? 1),
                'tpEmis' => (string) $contingency['tp_emis'],
                'tpAmb' => (int) ($context['environment'] ?? 2),
                'CFOP' => (string) ($context['cfop'] ?? '5932'),
                'natOp' => (string) ($context['natureza_operacao'] ?? 'Transporte'),
                'modal' => (string) ($context['modal'] ?? '01'),
                'UFIni' => (string) ($context['uf_ini'] ?? $context['uf'] ?? 'SP'),
                'UFFim' => (string) ($context['uf_fim'] ?? $context['uf'] ?? 'RJ'),
            ],
            'emit' => [
                'CNPJ' => $this->digits((string) ($context['cnpj'] ?? ''), 14),
                'xNome' => (string) ($context['razao_social'] ?? ''),
            ],
            'contingency' => $contingency,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{tp_emis: int, claims_provider_authorization: bool, contingency_mode: bool}  $contingency
     * @return array<string, mixed>
     */
    private function buildMdfePayload(array $context, array $contingency): array
    {
        $accessKey = $context['access_key'] ?? $this->buildAccessKey([
            'uf' => (string) ($context['uf'] ?? 'SP'),
            'cnpj' => (string) ($context['cnpj'] ?? ''),
            'model' => '58',
            'series' => (int) ($context['series'] ?? 1),
            'number' => (int) ($context['number'] ?? 1),
            'tp_emis' => $contingency['tp_emis'],
            'cnf' => $context['cnf'] ?? null,
            'issued_at' => $context['issued_at'] ?? null,
        ]);

        return [
            'model' => '58',
            'layout_version' => '3.00',
            'access_key' => $accessKey,
            'ide' => [
                'cUF' => BrazilianStateCode::ibgeFromUf((string) ($context['uf'] ?? 'SP')),
                'mod' => '58',
                'serie' => (int) ($context['series'] ?? 1),
                'nMDF' => (int) ($context['number'] ?? 1),
                'tpEmis' => (string) $contingency['tp_emis'],
                'tpAmb' => (int) ($context['environment'] ?? 2),
                'UFIni' => (string) ($context['uf_ini'] ?? $context['uf'] ?? 'SP'),
                'UFFim' => (string) ($context['uf_fim'] ?? $context['uf'] ?? 'RJ'),
                'modal' => (string) ($context['modal'] ?? '1'),
            ],
            'emit' => [
                'CNPJ' => $this->digits((string) ($context['cnpj'] ?? ''), 14),
                'xNome' => (string) ($context['razao_social'] ?? ''),
            ],
            'contingency' => $contingency,
        ];
    }

    private function normalizeModel(string $model): string
    {
        $model = strtolower(trim($model));
        if ($model === '') {
            throw new InvalidArgumentException('Modelo fiscal obrigatório.');
        }

        if (isset(self::MODELS[$model])) {
            return self::MODELS[$model];
        }

        if (in_array($model, self::MODELS, true)) {
            return $model;
        }

        throw new InvalidArgumentException('Modelo fiscal não suportado.');
    }

    private function mod11Digit(string $base43): string
    {
        $multipliers = [2, 3, 4, 5, 6, 7, 8, 9];
        $position = strlen($base43) - 1;
        $sum = 0;
        $weightIndex = 0;

        while ($position >= 0) {
            $sum += ((int) $base43[$position]) * $multipliers[$weightIndex % count($multipliers)];
            $position--;
            $weightIndex++;
        }

        $remainder = $sum % 11;
        $digit = $remainder === 0 || $remainder === 1 ? 0 : 11 - $remainder;

        return (string) $digit;
    }

    private function digits(string $value, int $length): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== $length) {
            throw new InvalidArgumentException('Documento emitente deve conter '.$length.' dígitos.');
        }

        return $digits;
    }

    private function inferContingencyTpEmis(?string $statusRetorno, string $model): int
    {
        if ($statusRetorno !== null && $statusRetorno !== '') {
            $decoded = json_decode($statusRetorno, true);
            if (is_array($decoded) && isset($decoded['tpEmis'])) {
                return (int) $decoded['tpEmis'];
            }
        }

        return $model === '65' ? 9 : 6;
    }
}
