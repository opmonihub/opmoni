<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\Fiscal\ErpFiscalConfiguration;
use App\Models\Erp\Fiscal\ErpFiscalConfigurationRevision;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalNumberSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpFiscalConfigurationRevisionService
{
    /** @param array<string, mixed> $configuration */
    public function replace(ErpEstablishment $establishment, array $configuration, ?int $actorUserId): ErpFiscalConfigurationRevision
    {
        return DB::transaction(function () use ($establishment, $configuration, $actorUserId): ErpFiscalConfigurationRevision {
            DB::table('erp_fiscal_configurations')->insertOrIgnore([
                'erp_company_id' => $establishment->erp_company_id,
                'erp_establishment_id' => $establishment->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $owner = ErpFiscalConfiguration::query()
                ->where('erp_company_id', $establishment->erp_company_id)
                ->where('erp_establishment_id', $establishment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousRevision = $owner->current_revision_id === null
                ? null
                : ErpFiscalConfigurationRevision::query()->whereKey($owner->current_revision_id)->first();
            $this->assertOperationNatures($establishment, $configuration);
            $this->initializeSequences($establishment, $configuration);

            if (isset($configuration['models']['65']['nfce_qr']['version'])) {
                $configuration['models']['65']['nfce_qr']['version'] = (int) $configuration['models']['65']['nfce_qr']['version'];
            }
            $canonical = $this->sortAssociativeKeys($configuration);
            $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $revision = ErpFiscalConfigurationRevision::query()->create([
                'erp_fiscal_configuration_id' => $owner->id,
                'revision' => (int) ($previousRevision?->revision ?? 0) + 1,
                'environment' => $configuration['environment'],
                'configuration' => $canonical,
                'configuration_sha256' => hash('sha256', $json),
                'created_by_user_id' => $actorUserId,
            ]);
            $owner->current_revision_id = $revision->id;
            $owner->save();

            return $revision;
        });
    }

    /** @param array<string, mixed> $configuration */
    private function assertOperationNatures(ErpEstablishment $establishment, array $configuration): void
    {
        foreach ($configuration['models'] ?? [] as $model => $settings) {
            $operationNatureId = $settings['operation_nature_id'] ?? null;
            if ($operationNatureId === null) {
                continue;
            }

            $exists = ErpOperationNature::query()
                ->whereKey($operationNatureId)
                ->where('erp_company_id', $establishment->erp_company_id)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('erp_establishment_id')->orWhere('erp_establishment_id', $establishment->id))
                ->exists();
            if (! $exists) {
                throw ValidationException::withMessages(["models.{$model}.operation_nature_id" => 'Natureza de operação ativa inválida para este estabelecimento']);
            }
        }
    }

    /** @param array<string, mixed> $configuration */
    private function initializeSequences(ErpEstablishment $establishment, array $configuration): void
    {
        foreach ($configuration['models'] ?? [] as $model => $settings) {
            $series = (int) $settings['series'];
            $nextNumber = (int) $settings['next_number'];
            $sequenceScope = [
                'erp_company_id' => $establishment->erp_company_id,
                'model' => (string) $model,
                'environment' => (string) $configuration['environment'],
                'series' => $series,
            ];
            $maximumAllocated = (int) ErpFiscalDocument::query()
                ->where('erp_company_id', $establishment->erp_company_id)
                ->where('model', (string) $model)
                ->where('environment', (string) $configuration['environment'])
                ->where('series', $series)
                ->max('number');
            $minimumNextNumber = $maximumAllocated + 1;

            DB::table('erp_fiscal_number_sequences')->insertOrIgnore([
                ...$sequenceScope,
                'next_number' => max($minimumNextNumber, $nextNumber),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = ErpFiscalNumberSequence::query()
                ->where($sequenceScope)
                ->lockForUpdate()
                ->firstOrFail();

            if ($nextNumber < (int) $sequence->next_number || $nextNumber < $minimumNextNumber) {
                throw ValidationException::withMessages(["models.{$model}.next_number" => 'O próximo número não pode retroceder nem reutilizar número já alocado']);
            }
            if ($nextNumber > (int) $sequence->next_number) {
                $sequence->next_number = $nextNumber;
                $sequence->save();
            }
        }
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function sortAssociativeKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortAssociativeKeys($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
