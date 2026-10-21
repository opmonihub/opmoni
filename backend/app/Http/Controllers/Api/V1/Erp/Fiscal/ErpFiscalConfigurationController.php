<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\ErpEstablishmentAccess;
use App\Erp\Fiscal\ErpFiscalConfigurationRevisionService;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Http\Requests\Erp\Fiscal\UpdateErpFiscalConfigurationRequest;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalConfigurationRevision;
use App\Models\Erp\Fiscal\ErpFiscalNumberSequence;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpFiscalConfigurationController extends ErpOperationsController
{
    private const MODELS = ['55', '65', '57', '58'];

    public function __construct(private ErpFiscalConfigurationRevisionService $revisions) {}

    public function show(Request $request, int $establishment): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission('erp.fiscal.configuration.show');
        $issuer = $this->issuerEstablishment($request, $establishment);
        $configuration = ErpFiscalConfiguration::query()
            ->where('erp_company_id', $context->company->id)
            ->where('erp_establishment_id', $issuer->id)
            ->with('currentRevision')
            ->first();
        $revision = $configuration?->currentRevision;
        $settings = $revision?->configuration ?? [];
        $certificate = ErpFiscalCertificate::query()
            ->where('erp_company_id', $context->company->id)
            ->where('erp_establishment_id', $issuer->id)
            ->where('status', 'active')
            ->with('currentRevision')
            ->orderByDesc('id')
            ->first();

        return response()->json(['data' => $this->serialize($issuer, $revision, $settings, $certificate)]);
    }

    public function update(UpdateErpFiscalConfigurationRequest $request, int $establishment): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission('erp.fiscal.configuration.update', true);
        $issuer = $this->issuerEstablishment($request, $establishment);
        $revision = $this->revisions->replace($issuer, $request->validated(), $request->user()?->id);

        $certificate = ErpFiscalCertificate::query()
            ->where('erp_company_id', $context->company->id)
            ->where('erp_establishment_id', $issuer->id)
            ->where('status', 'active')
            ->with('currentRevision')
            ->orderByDesc('id')
            ->first();

        return response()->json(['data' => $this->serialize($issuer, $revision, $revision->configuration, $certificate)]);
    }

    private function issuerEstablishment(Request $request, int $establishmentId): ErpEstablishment
    {
        $issuer = ErpEstablishment::query()
            ->where('erp_company_id', $this->companyId($request))
            ->where('status', 'active')
            ->whereKey($establishmentId)
            ->firstOrFail();
        app(ErpEstablishmentAccess::class)->assertResource(CurrentCompany::fromRequest($request), $issuer);

        return $issuer;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function serialize(ErpEstablishment $issuer, ?ErpFiscalConfigurationRevision $revision, array $settings, ?ErpFiscalCertificate $certificate): array
    {
        $modelSettings = $settings['models'] ?? [];
        $models = [];
        foreach (self::MODELS as $model) {
            $modelConfig = $modelSettings[$model] ?? [];
            $blockers = [];
            if ($revision === null) {
                $blockers[] = 'issuer_configuration_missing';
            } else {
                foreach (['tax_regime', 'state_registration', 'uf'] as $field) {
                    if (empty($settings['issuer'][$field])) {
                        $blockers[] = 'issuer_'.$field.'_missing';
                    }
                }
                foreach (['street', 'number', 'district', 'city_code', 'city_name', 'zip_code'] as $field) {
                    if (empty($settings['issuer']['address'][$field])) {
                        $blockers[] = 'issuer_address_'.$field.'_missing';
                    }
                }
            }
            if (! isset($modelConfig['series'], $modelConfig['next_number'])) {
                $blockers[] = 'model_configuration_missing';
            }
            if ($certificate === null || $certificate->currentRevision === null) {
                $blockers[] = 'certificate_missing';
            } elseif ($certificate->currentRevision->valid_from?->isFuture() || $certificate->currentRevision->valid_until?->isPast()) {
                $blockers[] = 'certificate_outside_validity';
            }
            if ($issuer->cnpj === null || $issuer->cnpj === '') {
                $blockers[] = 'issuer_cnpj_missing';
            } elseif (! preg_match('/^\d{14}$/', $issuer->cnpj)) {
                $blockers[] = 'issuer_identifier_unsupported';
            }
            if (in_array($model, ['55', '65'], true) && empty($modelConfig['operation_nature_id'])) {
                $blockers[] = 'operation_nature_missing';
            }
            if ($model === '65') {
                $qr = $modelConfig['nfce_qr'] ?? [];
                if (($qr['version'] ?? null) !== 3 || ! in_array($qr['mode'] ?? null, ['online', 'offline'], true) || empty($qr['consultation_url'])) {
                    $blockers[] = 'nfce_qr_configuration_missing';
                }
            }
            if ($model === '57' && ! $this->hasFields($modelConfig['transport'] ?? [], ['cte_type', 'service_type', 'emission_type', 'modal', 'operation_nature', 'cfop', 'sender', 'receiver', 'taker', 'route', 'tax', 'cargo'])) {
                $blockers[] = 'typed_transport_service_missing';
            }
            if ($model === '58' && ! $this->hasFields($modelConfig['transport'] ?? [], ['emission_type', 'modal', 'issuer_type', 'rntrc', 'route_ufs', 'municipalities', 'vehicle', 'driver', 'documents', 'cargo'])) {
                $blockers[] = 'typed_transport_manifest_missing';
            }
            try {
                app(ErpFiscalSchemaValidator::class)->entrypoint($model, 'request');
            } catch (\Throwable) {
                $blockers[] = 'official_schema_missing';
            }
            $sharedNextNumber = $revision !== null && isset($modelConfig['series'])
                ? ErpFiscalNumberSequence::query()
                    ->where('erp_company_id', $issuer->erp_company_id)->where('model', $model)
                    ->where('environment', $revision->environment)->where('series', $modelConfig['series'])->value('next_number')
                : null;
            $models[$model] = [
                'series' => isset($modelConfig['series']) ? (int) $modelConfig['series'] : null,
                'next_number' => $sharedNextNumber === null ? null : (int) $sharedNextNumber,
                'initial_next_number' => isset($modelConfig['next_number']) ? (int) $modelConfig['next_number'] : null,
                'operation_nature_id' => $modelConfig['operation_nature_id'] ?? null,
                'shared_next_number' => $sharedNextNumber === null ? null : (int) $sharedNextNumber,
                'transport_prerequisites' => $this->configuredTransportFields($model, $modelConfig['transport'] ?? []),
                ...($model === '65' ? ['nfce_qr' => $this->safeQrConfiguration($modelConfig['nfce_qr'] ?? [])] : []),
                'schema_release' => $this->schemaRelease($model),
                'readiness' => [
                    'status' => $blockers === [] ? 'locally_configured' : 'blocked',
                    'blockers' => array_values(array_unique($blockers)),
                ],
            ];
        }

        return [
            'establishment' => [
                'id' => $issuer->id,
                'name' => $issuer->name,
                'cnpj' => $issuer->cnpj,
                'status' => $issuer->status,
            ],
            'revision_id' => $revision?->id,
            'revision' => $revision?->revision,
            'environment' => $revision?->environment,
            'issuer' => $settings['issuer'] ?? null,
            'models' => $models,
            'release_gates' => [
                'provider' => 'unavailable',
                'production_transmission_enabled' => false,
                'production_blocker' => 'provider_unavailable',
            ],
        ];
    }

    /** @param mixed $value @param list<string> $fields */
    private function hasFields(mixed $value, array $fields): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value) || $value[$field] === null || $value[$field] === '' || $value[$field] === []) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $transport @return list<string> */
    private function configuredTransportFields(string $model, mixed $transport): array
    {
        if (! is_array($transport)) {
            return [];
        }
        $required = match ($model) {
            '57' => ['cte_type', 'service_type', 'emission_type', 'modal', 'operation_nature', 'cfop', 'sender', 'receiver', 'taker', 'route', 'tax', 'cargo'],
            '58' => ['emission_type', 'modal', 'issuer_type', 'rntrc', 'route_ufs', 'municipalities', 'vehicle', 'driver', 'documents', 'cargo'],
            default => [],
        };

        return array_values(array_filter($required, fn (string $field): bool => isset($transport[$field]) && $transport[$field] !== '' && $transport[$field] !== []));
    }

    /** @param mixed $configuration @return array<string, mixed> */
    private function safeQrConfiguration(mixed $configuration): array
    {
        return is_array($configuration)
            ? array_intersect_key($configuration, array_flip(['version', 'mode', 'consultation_url']))
            : [];
    }

    private function schemaRelease(string $model): string
    {
        return match ($model) {
            '55' => 'PL_010f_v1.04',
            '65' => 'PL_010e_v1.02',
            '57' => 'PL_CTe_400_NT2026.002 RTC_1.01_corr_2',
            '58' => 'PL_MDFe_300b_NT012025_1.04 (internal 1.05)',
        };
    }
}
