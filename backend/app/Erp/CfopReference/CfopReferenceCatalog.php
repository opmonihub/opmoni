<?php

namespace App\Erp\CfopReference;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use JsonException;

final class CfopReferenceCatalog
{
    private const REFERENCE_VERSION = 'IT 2023.002 v2.10';

    private const SOURCE_SHA256 = '577e05eec452294945d0e9df1f9bb9b21a4af115938e75ec74cf6a541ae4dacf';

    private const ARTIFACT_SHA256 = '208fd4d3c01712cd8a080656203f7503b5668fa7a459e11e1364d098c059c2ee';

    private const REFERENCE_PUBLICATION_DATE = '2026-09-04';

    private const SOURCE_URL = 'https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM%3D';

    private const NFCE_REFERENCE_CODES = [
        '5101', '5102', '5103', '5104', '5115', '5405', '5656', '5667', '5910', '5933',
    ];

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|string>}
     */
    public function search(Request $request): array
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'direction' => ['sometimes', Rule::in(['inbound', 'outbound'])],
            'model' => ['sometimes', Rule::in(['55', '65'])],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);

        $records = $this->records();
        $query = mb_strtolower(trim($filters['q'] ?? ''));
        $direction = $filters['direction'] ?? null;
        $model = $filters['model'] ?? null;

        $records = array_values(array_filter($records, function (array $record) use ($query, $direction, $model): bool {
            if ($query !== '' && ! str_contains(mb_strtolower($record['code'].' '.$record['description'].' '.$record['explanatory_note']), $query)) {
                return false;
            }
            if ($direction !== null && $record['applicability']['direction'] !== $direction) {
                return false;
            }
            if ($model === '55' && $record['source_metadata']['indNFe'] !== '1') {
                return false;
            }
            if ($model === '65' && ! $record['applicability']['nfce_reference_listed'] && ! $record['applicability']['nfce_conditional_uf_exception']) {
                return false;
            }

            return true;
        }));
        usort($records, fn (array $left, array $right): int => $left['code'] <=> $right['code']);

        $perPage = min(100, (int) ($filters['per_page'] ?? 15));
        $page = (int) ($filters['page'] ?? 1);
        $total = count($records);

        return [
            'data' => array_slice($records, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'reference_version' => self::REFERENCE_VERSION,
                'reference_publication_date' => self::REFERENCE_PUBLICATION_DATE,
                'source_url' => self::SOURCE_URL,
                'source_sha256' => self::SOURCE_SHA256,
                'source_artifact_sha256' => self::ARTIFACT_SHA256,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        if (! preg_match('/^[123567][0-9]{3}$/', $code)) {
            return null;
        }

        foreach ($this->records() as $record) {
            if ($record['code'] === $code) {
                return $record;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        $path = config('erp.cfop_reference_path', app_path('Erp/CfopReference/Data/cfop-v210-rows.json'));
        $contents = is_string($path) ? @file_get_contents($path) : false;
        if (! is_string($contents) || ! hash_equals(self::ARTIFACT_SHA256, hash('sha256', $contents))) {
            throw new CfopReferenceUnavailable('The verified CFOP reference artifact is unavailable.');
        }

        try {
            $sourceRows = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CfopReferenceUnavailable('The verified CFOP reference artifact is invalid.', previous: $exception);
        }

        if (! is_array($sourceRows) || count($sourceRows) !== 620 || ($sourceRows[0]['A1'] ?? null) !== 'CFOP') {
            throw new CfopReferenceUnavailable('The verified CFOP reference artifact has an unexpected structure.');
        }

        $records = [];
        $seenCodes = [];
        foreach (array_slice($sourceRows, 1) as $row) {
            $excelRow = count($records) + 2;
            $code = $row['A'.$excelRow] ?? null;
            $title = $row['M'.$excelRow] ?? null;
            $note = $row['N'.$excelRow] ?? null;
            if (! is_string($code) || ! preg_match('/^[123567][0-9]{3}$/', $code)
                || isset($seenCodes[$code]) || ! is_string($title) || ! is_string($note)) {
                throw new CfopReferenceUnavailable('The verified CFOP reference artifact contains an invalid row.');
            }
            $seenCodes[$code] = true;

            $sourceMetadata = [
                'validity_start_serial' => $row['B'.$excelRow] ?? '',
                'validity_end_serial' => $row['C'.$excelRow] ?? '',
                'indNFe' => $row['D'.$excelRow] ?? '',
                'indComunica' => $row['E'.$excelRow] ?? '',
                'indTransp' => $row['F'.$excelRow] ?? '',
                'indDevol' => $row['G'.$excelRow] ?? '',
                'indRetor' => $row['H'.$excelRow] ?? '',
                'indAnula' => $row['I'.$excelRow] ?? '',
                'indRemes' => $row['J'.$excelRow] ?? '',
                'indComb' => $row['K'.$excelRow] ?? '',
                'indExcIBSCBS' => $row['L'.$excelRow] ?? '',
            ];
            foreach ($sourceMetadata as $key => $value) {
                if (! is_string($value)) {
                    throw new CfopReferenceUnavailable('The verified CFOP reference artifact contains invalid metadata.');
                }
            }

            $records[] = [
                'code' => $code,
                'description' => $title,
                'explanatory_note' => $note,
                'reference_version' => self::REFERENCE_VERSION,
                'source_metadata' => $sourceMetadata,
                'applicability' => $this->applicability($code),
            ];
        }

        if (count($records) !== 619) {
            throw new CfopReferenceUnavailable('The verified CFOP reference artifact is incomplete.');
        }

        return $records;
    }

    /** @return array{status: string, direction: string, nfce_reference_listed: bool, nfce_conditional_uf_exception: bool, reason?: string} */
    private function applicability(string $code): array
    {
        $prefix = $code[0];
        $direction = in_array($prefix, ['1', '2', '3'], true) ? 'inbound' : 'outbound';
        if ($prefix === '7') {
            return [
                'status' => 'unsupported',
                'direction' => $direction,
                'nfce_reference_listed' => in_array($code, self::NFCE_REFERENCE_CODES, true),
                'nfce_conditional_uf_exception' => $code === '5949',
                'reason' => 'export_not_supported',
            ];
        }

        return [
            'status' => 'requires_context',
            'direction' => $direction,
            'nfce_reference_listed' => in_array($code, self::NFCE_REFERENCE_CODES, true),
            'nfce_conditional_uf_exception' => $code === '5949',
        ];
    }
}
