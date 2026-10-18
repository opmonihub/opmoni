<?php

namespace App\Erp;

use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCategory;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpUnit;
use App\Services\BrazilianTaxId;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ErpCatalogWriteService
{
    public function __construct(
        private ErpCatalogScope $scope,
        private BrazilianTaxId $taxId,
    ) {}

    /** @param Closure(CurrentCompany): Response $write */
    public function run(Request $request, Closure $write): Response
    {
        $context = CurrentCompany::fromRequest($request);

        return DB::transaction(function () use ($request, $context, $write): Response {
            AccessSession::assertOwnership($request);
            ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);
            $context->assertCanMutate();

            $response = $write($context);
            AccessSession::assertOwnership($request);

            return $response;
        });
    }

    /** @param array<string, mixed> $payload */
    public function createProduct(int $companyId, array $payload): ErpProduct
    {
        $data = $this->productData($payload, $companyId);
        $variants = $data['variants'] ?? [];
        unset($data['variants']);
        if (($data['kind'] ?? 'goods') === 'service') {
            $data['track_stock'] = false;
        }

        $product = ErpProduct::query()->create([
            ...$data,
            'erp_company_id' => $companyId,
            'status' => 'active',
        ]);
        foreach ($variants as $variant) {
            unset($variant['id'], $variant['status']);
            $product->variants()->create($variant);
        }

        return $product->load('variants');
    }

    /** @param array<string, mixed> $payload */
    public function updateProduct(CurrentCompany $context, ErpProduct $product, array $payload): ErpProduct
    {
        $companyId = $context->company->id;
        $data = $this->productData($payload, $companyId, partial: true, current: $product);
        $this->validateVariantIds($data['variants'] ?? [], $product);
        if (collect($data['variants'] ?? [])->contains(fn (array $variant): bool => ($variant['status'] ?? null) === 'archived')) {
            $context->assertPermission('erp.products.archive', write: true);
        }
        $variants = $data['variants'] ?? null;
        unset($data['variants']);
        $effectiveKind = $data['kind'] ?? $product->kind;
        if ($effectiveKind === 'service') {
            $data['track_stock'] = false;
        }
        if ($data !== []) {
            $product->fill($data);
            $product->save();
        }
        if (is_array($variants)) {
            foreach ($variants as $variant) {
                if (isset($variant['id'])) {
                    $row = $product->variants()->whereKey($variant['id'])->firstOrFail();
                    $row->fill(collect($variant)->except('id')->all());
                    $row->save();
                } else {
                    unset($variant['status']);
                    $product->variants()->create($variant);
                }
            }
        }

        return $product->fresh('variants');
    }

    /** @param array<string, mixed> $payload */
    public function createContact(int $companyId, array $payload): ErpContact
    {
        $data = $this->contactData($payload);

        return ErpContact::query()->create([
            ...$data,
            'erp_company_id' => $companyId,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function updateContact(ErpContact $contact, array $payload): ErpContact
    {
        $contact->fill($this->contactData($payload, partial: true));
        $contact->save();

        return $contact->fresh();
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function productData(array $payload, int $companyId, bool $partial = false, ?ErpProduct $current = null): array
    {
        $data = Validator::make($payload, [
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:goods,service'],
            'track_stock' => ['sometimes', 'boolean'],
            'erp_unit_id' => [$partial ? 'sometimes' : 'required', 'integer', 'min:1'],
            'erp_brand_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'erp_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sale_price' => ['sometimes', 'nullable', ...ErpCatalogDecimal::widthRule(16, 4)],
            'warranty_months' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'warranty_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['sometimes', 'integer', 'min:1'],
            'variants.*.sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sale_price' => ['sometimes', 'nullable', ...ErpCatalogDecimal::widthRule(16, 4)],
            'variants.*.barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'variants.*.status' => ['sometimes', 'in:archived'],
        ])->validate();

        if (! $partial && collect($data['variants'] ?? [])->contains(fn (array $variant): bool => array_key_exists('id', $variant))) {
            throw ValidationException::withMessages(['variants' => 'Novas variantes não podem informar um identificador.']);
        }

        if (array_key_exists('sku', $data)) {
            $this->assertSkuAvailable($data['sku'], $companyId, $current?->id, 'sku');
        }
        foreach ($data['variants'] ?? [] as $index => $variant) {
            if (array_key_exists('sku', $variant)) {
                $this->assertSkuAvailable($variant['sku'], $companyId, $current?->id, "variants.{$index}.sku");
            }
        }

        if (isset($data['erp_unit_id'])) {
            $this->scope->assertActiveReference(
                ErpUnit::class,
                $companyId,
                (int) $data['erp_unit_id'],
                'erp_unit_id',
                $current?->erp_unit_id !== null ? (int) $current->erp_unit_id : null,
            );
        }
        if (array_key_exists('erp_brand_id', $data) && $data['erp_brand_id'] !== null) {
            $this->scope->assertActiveReference(ErpBrand::class, $companyId, (int) $data['erp_brand_id'], 'erp_brand_id', $current?->erp_brand_id !== null ? (int) $current->erp_brand_id : null);
        }
        if (array_key_exists('erp_category_id', $data) && $data['erp_category_id'] !== null) {
            $this->scope->assertActiveReference(ErpCategory::class, $companyId, (int) $data['erp_category_id'], 'erp_category_id', $current?->erp_category_id !== null ? (int) $current->erp_category_id : null);
        }

        return $data;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function contactData(array $payload, bool $partial = false): array
    {
        $data = Validator::make($payload, [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_customer' => ['sometimes', 'boolean'],
            'is_supplier' => ['sometimes', 'boolean'],
            'address' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ])->validate();
        if (isset($data['tax_id']) && is_string($data['tax_id']) && $data['tax_id'] !== '') {
            $data['tax_id'] = $this->taxId->normalize($data['tax_id']);
        }

        return $data;
    }

    /** @param list<array<string, mixed>> $variants */
    private function validateVariantIds(array $variants, ErpProduct $product): void
    {
        $seen = [];
        foreach ($variants as $index => $variant) {
            if (! isset($variant['id'])) {
                continue;
            }
            $id = (int) $variant['id'];
            if (isset($seen[$id])) {
                throw ValidationException::withMessages(["variants.{$index}.id" => 'A variante foi informada mais de uma vez.']);
            }
            $seen[$id] = true;
            if (! $product->variants()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(["variants.{$index}.id" => 'Variante inválida para este produto.']);
            }
        }
    }

    private function assertSkuAvailable(?string $sku, int $companyId, ?int $parentProductId, string $field): void
    {
        if ($sku === null || $sku === '') {
            return;
        }

        $productQuery = ErpProduct::query()->where('erp_company_id', $companyId)->where('sku', $sku);
        if ($parentProductId !== null) {
            $productQuery->whereKeyNot($parentProductId);
        }
        if ($productQuery->exists()) {
            throw ValidationException::withMessages([$field => 'Este SKU já está em uso nesta empresa.']);
        }

        $variantQuery = ErpProductVariant::query()->where('sku', $sku)->whereHas('product', function ($query) use ($companyId, $parentProductId): void {
            $query->where('erp_company_id', $companyId);
            if ($parentProductId !== null) {
                $query->whereKeyNot($parentProductId);
            }
        });
        if ($variantQuery->exists()) {
            throw ValidationException::withMessages([$field => 'Este SKU já está em uso nesta empresa.']);
        }
    }
}
