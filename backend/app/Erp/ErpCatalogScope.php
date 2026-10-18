<?php

namespace App\Erp;

use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ErpCatalogScope
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function assertBelongsToCompany(string $modelClass, int $companyId, ?int $id, string $field): void
    {
        if ($id === null) {
            return;
        }

        $exists = $modelClass::query()
            ->whereKey($id)
            ->where('erp_company_id', $companyId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => 'Registro inválido para esta empresa']);
        }
    }

    public function assertEstablishmentBelongsToCompany(int $companyId, ?int $establishmentId, string $field = 'erp_establishment_id'): void
    {
        if ($establishmentId === null) {
            return;
        }

        $exists = ErpEstablishment::query()
            ->whereKey($establishmentId)
            ->where('erp_company_id', $companyId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => 'Estabelecimento inválido para esta empresa']);
        }
    }

    public function assertActiveEstablishment(int $companyId, ?int $establishmentId, string $field = 'erp_establishment_id'): void
    {
        if ($establishmentId !== null && ! ErpEstablishment::query()->whereKey($establishmentId)->where('erp_company_id', $companyId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages([$field => 'Estabelecimento ativo inválido para esta empresa']);
        }
    }

    public function assertProductBelongsToCompany(int $companyId, int $productId): void
    {
        $this->assertBelongsToCompany(ErpProduct::class, $companyId, $productId, 'erp_product_id');
    }

    public function assertVariantBelongsToCompany(int $companyId, ?int $variantId, string $field = 'erp_product_variant_id'): void
    {
        if ($variantId === null) {
            return;
        }
        if (! ErpProductVariant::query()->whereKey($variantId)->whereHas('product', fn ($query) => $query->where('erp_company_id', $companyId)->where('status', 'active'))->where('status', 'active')->exists()) {
            throw ValidationException::withMessages([$field => 'Variante inválida para esta empresa']);
        }
    }

    /** @return array{per_page: int, search: ?string, status: ?string} */
    public function listParams(Request $request): array
    {
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';

        return [
            'per_page' => min(100, max(1, (int) $request->query('per_page', 15))),
            'search' => is_string($request->query('search')) ? trim($request->query('search')) : null,
            'status' => $status === 'all' ? null : $status,
        ];
    }
}
