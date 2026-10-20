<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogPrintGeometry;
use App\Erp\ErpCatalogWriteService;
use App\Erp\ErpCompanyOperatingSettings;
use App\Erp\ErpLabelBarcodeValidator;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpCatalogPrintLayout;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ErpCatalogPrintController extends Controller
{
    private const MAX_LINES = 100;

    private const MAX_COPIES = 250;

    public function __construct(
        private ErpCompanyOperatingSettings $settings,
        private ErpCatalogWriteService $writes,
    ) {}

    public function showSettings(Request $request): JsonResponse
    {
        $company = CurrentCompany::fromRequest($request)->company;

        return response()->json(['data' => $this->legacySettings($company)]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $request->validate([
                'label_width_mm' => ['required', 'integer', 'min:20', 'max:200'],
                'label_height_mm' => ['required', 'integer', 'min:10', 'max:200'],
                'show_price' => ['required', 'boolean'],
                'show_barcode' => ['required', 'boolean'],
            ]);
            $data['label_width_mm'] = (int) $data['label_width_mm'];
            $data['label_height_mm'] = (int) $data['label_height_mm'];
            $data['show_price'] = (bool) $data['show_price'];
            $data['show_barcode'] = (bool) $data['show_barcode'];

            /** @var ErpCompany $company */
            $company = ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);
            $this->settings->mergeOperating($company, ['catalog_print' => $data]);
            $company->save();

            return response()->json(['data' => $this->legacySettings($company)]);
        });
    }

    public function productLabels(Request $request): JsonResponse
    {
        return $this->writes->run($request, function (CurrentCompany $context) use ($request): JsonResponse {
            /** @var ErpCompany $company */
            $company = ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);
            $input = $request->all();
            $hasTypedItems = array_key_exists('items', $input);
            $hasProductIds = array_key_exists('product_ids', $input);
            if ($hasTypedItems === $hasProductIds) {
                throw ValidationException::withMessages([
                    'items' => 'Informe uma única forma de selecionar os produtos.',
                    'product_ids' => 'Informe uma única forma de selecionar os produtos.',
                ]);
            }

            $validated = $this->validateSelection($request, $hasTypedItems);
            $legacy = ! $hasTypedItems;
            $items = $legacy
                ? $this->legacyItems($validated['product_ids'])
                : $validated['items'];
            $resolved = $this->resolveLayout($company, $validated['layout_id'] ?? null);
            ErpCatalogPrintGeometry::assertLayoutFits($resolved['layout']);
            $totalCopies = array_sum(array_map(fn (array $item): int => $legacy ? 1 : (int) $item['quantity'], $items));
            $pageHeight = ErpCatalogPrintGeometry::continuousOutputHeight($resolved['layout'], $totalCopies);

            $productIds = array_values(array_unique(array_map(fn (array $item): int => (int) $item['product_id'], $items)));
            $products = ErpProduct::query()
                ->where('erp_company_id', $company->id)
                ->where('status', 'active')
                ->whereIn('id', $productIds)
                ->with(['variants' => fn ($query) => $query->where('status', 'active')])
                ->get()
                ->keyBy(fn (ErpProduct $product): int => (int) $product->id);

            $errors = [];
            $rows = [];
            $copiesSeen = 0;
            foreach ($items as $index => $item) {
                $product = $products->get((int) $item['product_id']);
                if (! $product instanceof ErpProduct) {
                    $key = $legacy ? "product_ids.{$index}" : "items.{$index}.product_id";
                    $errors[$key] = ['Selecione um produto ativo desta empresa.'];

                    continue;
                }

                $variant = null;
                if (! $legacy && ($item['variant_id'] ?? null) !== null) {
                    $variant = $product->variants->first(fn (ErpProductVariant $candidate): bool => (int) $candidate->id === (int) $item['variant_id']);
                    if (! $variant instanceof ErpProductVariant) {
                        $errors["items.{$index}.variant_id"] = ['Selecione uma variante ativa deste produto.'];

                        continue;
                    }
                }

                $quantity = $legacy ? 1 : (int) $item['quantity'];
                $copiesSeen += $quantity;
                if ($copiesSeen > self::MAX_COPIES) {
                    $errors[($legacy ? "product_ids.{$index}" : "items.{$index}.quantity")] = ['A seleção pode gerar no máximo 250 etiquetas.'];
                }

                $barcode = $variant?->barcode ?? ($variant === null ? $product->barcode : null);
                $barcodeType = $variant?->barcode_type ?? $product->barcode_type;
                if ($resolved['toggles']['show_barcode'] && (! is_string($barcode) || ! ErpLabelBarcodeValidator::isValid((string) $barcodeType, $barcode))) {
                    $key = $legacy ? "product_ids.{$index}" : "items.{$index}.barcode";
                    $errors[$key] = ['Informe um código de barras completo e válido para a simbologia selecionada.'];
                }

                $price = $variant !== null && $variant->sale_price !== null
                    ? (string) $variant->sale_price
                    : (string) $product->sale_price;
                $rows[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'barcode' => $barcode,
                    'barcode_type' => $barcodeType,
                    'sale_price' => $price,
                    'quantity' => $quantity,
                ];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $data = $legacy
                ? array_map(fn (array $row): array => [
                    'id' => $row['product']->id,
                    'name' => $row['product']->name,
                    'sku' => $row['product']->sku,
                    'barcode' => $row['barcode'],
                    'sale_price' => $row['sale_price'],
                ], $rows)
                : array_map(fn (array $row): array => [
                    'id' => $row['product']->id,
                    'variant_id' => $row['variant']?->id,
                    'product_name' => $row['product']->name,
                    'variant_name' => $row['variant']?->name,
                    'sku' => $row['variant']?->sku ?? $row['product']->sku,
                    'barcode' => $row['barcode'],
                    'barcode_type' => $row['barcode_type'],
                    'sale_price' => $row['sale_price'],
                    'quantity' => $row['quantity'],
                ], $rows);

            return response()->json([
                'data' => $data,
                'meta' => [
                    'layout' => $this->serializeResolvedLayout($resolved['layout']),
                    'toggles' => $resolved['toggles'],
                    'compatibility_fallback' => $resolved['compatibility_fallback'],
                    'total_copies' => $totalCopies,
                    'output_page_height_mm' => $pageHeight,
                ],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function validateSelection(Request $request, bool $typed): array
    {
        $this->assertSelectionIntegers($request->all(), $typed);

        $rules = [
            'layout_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'items' => [$typed ? 'required' : 'prohibited', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'items.*.product_id' => [$typed ? 'required' : 'prohibited', 'integer', 'min:1'],
            'items.*.variant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'items.*.quantity' => [$typed ? 'required' : 'prohibited', 'integer', 'min:1', 'max:'.self::MAX_COPIES],
            'product_ids' => [$typed ? 'prohibited' : 'required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'product_ids.*' => [$typed ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];

        return Validator::make($request->all(), $rules)->validate();
    }

    /** @param array<string, mixed> $input */
    private function assertSelectionIntegers(array $input, bool $typed): void
    {
        $errors = [];
        if (array_key_exists('layout_id', $input) && $input['layout_id'] !== null && ! $this->isPositiveIntegerValue($input['layout_id'], PHP_INT_MAX)) {
            $errors['layout_id'] = ['Informe um identificador inteiro válido.'];
        }

        if ($typed && is_array($input['items'] ?? null)) {
            foreach ($input['items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (array_key_exists('product_id', $item) && ! $this->isPositiveIntegerValue($item['product_id'], PHP_INT_MAX)) {
                    $errors["items.{$index}.product_id"] = ['Informe um identificador inteiro válido.'];
                }
                if (array_key_exists('variant_id', $item) && $item['variant_id'] !== null && ! $this->isPositiveIntegerValue($item['variant_id'], PHP_INT_MAX)) {
                    $errors["items.{$index}.variant_id"] = ['Informe um identificador inteiro válido.'];
                }
                if (array_key_exists('quantity', $item) && ! $this->isPositiveIntegerValue($item['quantity'], self::MAX_COPIES)) {
                    $errors["items.{$index}.quantity"] = ['Informe uma quantidade inteira entre 1 e 250.'];
                }
            }
        }

        if (! $typed && is_array($input['product_ids'] ?? null)) {
            foreach ($input['product_ids'] as $index => $id) {
                if (! $this->isPositiveIntegerValue($id, PHP_INT_MAX)) {
                    $errors["product_ids.{$index}"] = ['Informe um identificador inteiro válido.'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isPositiveIntegerValue(mixed $value, int $maximum): bool
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= $maximum;
        }
        if (! is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return false;
        }

        $canonical = ltrim($value, '0');
        if ($canonical === '') {
            return false;
        }
        $max = (string) $maximum;
        if (strlen($canonical) < strlen($max)) {
            return true;
        }

        return strlen($canonical) === strlen($max) && strcmp($canonical, $max) <= 0;
    }

    /** @param list<int|string> $ids
     * @return array<int, array{product_id: int}>
     */
    private function legacyItems(array $ids): array
    {
        $items = [];
        $seen = [];
        foreach ($ids as $index => $id) {
            $normalized = (string) (int) $id;
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $items[$index] = ['product_id' => (int) $id];
        }

        return $items;
    }

    /** @return array{layout: array<string, mixed>, toggles: array{show_price: bool, show_barcode: bool}, compatibility_fallback: bool} */
    private function resolveLayout(ErpCompany $company, mixed $requestedId): array
    {
        $settings = $this->legacySettings($company);
        $layout = null;
        if ($requestedId !== null) {
            $layout = ErpCatalogPrintLayout::query()
                ->where('erp_company_id', $company->id)
                ->where('status', 'active')
                ->whereKey((int) $requestedId)
                ->first();
            if (! $layout instanceof ErpCatalogPrintLayout) {
                throw ValidationException::withMessages(['layout_id' => 'Selecione uma configuração ativa desta empresa.']);
            }
        } else {
            $layout = ErpCatalogPrintLayout::query()
                ->where('erp_company_id', $company->id)
                ->where('status', 'active')
                ->where('is_default', true)
                ->first();
        }

        if ($layout instanceof ErpCatalogPrintLayout) {
            $resolved = [
                'id' => $layout->id,
                'erp_company_id' => $layout->erp_company_id,
                'name' => $layout->name,
                'description' => $layout->description,
                'label_width_mm' => (string) $layout->label_width_mm,
                'label_height_mm' => (string) $layout->label_height_mm,
                'paper_width_mm' => (string) $layout->paper_width_mm,
                'paper_height_mm' => $layout->paper_height_mm !== null ? (string) $layout->paper_height_mm : null,
                'top_margin_mm' => (string) $layout->top_margin_mm,
                'left_margin_mm' => (string) $layout->left_margin_mm,
                'row_spacing_mm' => (string) $layout->row_spacing_mm,
                'column_spacing_mm' => (string) $layout->column_spacing_mm,
                'columns_per_row' => $layout->columns_per_row,
                'labels_per_sheet' => $layout->labels_per_sheet,
                'is_continuous' => $layout->is_continuous,
                'is_default' => $layout->is_default,
                'status' => $layout->status,
            ];

            return [
                'layout' => $resolved,
                'toggles' => ['show_price' => $settings['show_price'], 'show_barcode' => $settings['show_barcode']],
                'compatibility_fallback' => false,
            ];
        }

        $legacyLayout = [
            'id' => null,
            'erp_company_id' => $company->id,
            'name' => 'Configuração antiga',
            'description' => null,
            'label_width_mm' => $this->legacyDecimal($settings['label_width_mm'], 'label_width_mm'),
            'label_height_mm' => $this->legacyDecimal($settings['label_height_mm'], 'label_height_mm'),
            'paper_width_mm' => $this->legacyDecimal($settings['label_width_mm'], 'label_width_mm'),
            'paper_height_mm' => $this->legacyDecimal($settings['label_height_mm'], 'label_height_mm'),
            'top_margin_mm' => '0.0000',
            'left_margin_mm' => '0.0000',
            'row_spacing_mm' => '0.0000',
            'column_spacing_mm' => '0.0000',
            'columns_per_row' => 1,
            'labels_per_sheet' => 1,
            'is_continuous' => false,
            'is_default' => false,
            'status' => 'active',
        ];

        return [
            'layout' => $legacyLayout,
            'toggles' => ['show_price' => $settings['show_price'], 'show_barcode' => $settings['show_barcode']],
            'compatibility_fallback' => true,
        ];
    }

    /** @return array<string, int|bool> */
    private function legacySettings(ErpCompany $company): array
    {
        $stored = is_array($company->operating_settings) ? ($company->operating_settings['catalog_print'] ?? []) : [];

        return [
            'label_width_mm' => $this->legacyIntegerSetting($stored['label_width_mm'] ?? 50, 'label_width_mm', 20, 200),
            'label_height_mm' => $this->legacyIntegerSetting($stored['label_height_mm'] ?? 30, 'label_height_mm', 10, 200),
            'show_price' => $this->legacyBooleanSetting($stored['show_price'] ?? true, 'show_price'),
            'show_barcode' => $this->legacyBooleanSetting($stored['show_barcode'] ?? true, 'show_barcode'),
        ];
    }

    private function legacyIntegerSetting(mixed $value, string $attribute, int $minimum, int $maximum): int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1)) {
            throw ValidationException::withMessages([$attribute => 'A configuração antiga possui uma dimensão inválida.']);
        }

        $normalized = (int) $value;
        if ($normalized < $minimum || $normalized > $maximum) {
            throw ValidationException::withMessages([$attribute => 'A configuração antiga possui uma dimensão inválida.']);
        }

        return $normalized;
    }

    private function legacyBooleanSetting(mixed $value, string $attribute): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw ValidationException::withMessages([$attribute => 'A configuração antiga possui um valor inválido.']);
    }

    private function legacyDecimal(mixed $value, string $attribute): string
    {
        if (! is_int($value) || $value < 1) {
            throw ValidationException::withMessages([$attribute => 'A configuração antiga possui uma dimensão inválida.']);
        }

        return $value.'.0000';
    }

    /** @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function serializeResolvedLayout(array $layout): array
    {
        unset($layout['erp_company_id'], $layout['status']);

        return $layout;
    }
}
