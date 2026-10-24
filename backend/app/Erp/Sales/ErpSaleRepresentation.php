<?php

namespace App\Erp\Sales;

use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Validation\ValidationException;

class ErpSaleRepresentation
{
    public const VERSION = 'commercial-layout-v1';

    public const DEFAULT_SETTINGS = [
        'version' => self::VERSION,
        'labels' => [
            'title' => 'Venda comercial', 'number' => 'Número', 'company' => 'Empresa',
            'establishment' => 'Estabelecimento', 'customer' => 'Cliente', 'description' => 'Descrição',
            'quantity' => 'Quantidade', 'unit_price' => 'Valor unitário', 'line_total' => 'Total do item',
            'subtotal' => 'Subtotal', 'discount' => 'Descontos', 'taxes' => 'Tributos comerciais', 'total' => 'Total',
        ],
        'show_customer' => true,
        'footer_text' => null,
    ];

    /** @return array{erp_invoice_layout_id: ?int, commercial_layout_snapshot: ?array<string, mixed>} */
    public function capture(int $companyId): array
    {
        $state = ErpCompanySetupState::query()->forCompany($companyId)->lockForUpdate()->first();
        if ($state?->default_invoice_layout_id === null) {
            return ['erp_invoice_layout_id' => null, 'commercial_layout_snapshot' => null];
        }
        $layout = ErpInvoiceLayout::query()->where('erp_company_id', $companyId)->whereKey($state->default_invoice_layout_id)->lockForUpdate()->first();
        if ($layout === null || $layout->status !== 'active') {
            throw ValidationException::withMessages(['default_invoice_layout_id' => 'Selecione um modelo comercial ativo desta empresa.']);
        }
        $settings = $this->settings($layout->layout_settings);
        if ($settings === null) {
            throw ValidationException::withMessages(['default_invoice_layout_id' => 'Revise os rótulos e as opções do modelo comercial.']);
        }

        return [
            'erp_invoice_layout_id' => $layout->id,
            'commercial_layout_snapshot' => [
                'version' => self::VERSION, 'layout_id' => $layout->id, 'name' => $layout->name,
                'settings' => $settings,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function viewData(ErpSale $sale): array
    {
        $layout = $sale->commercial_layout_snapshot;
        $facts = $sale->commercial_snapshot;
        $settings = is_array($layout) ? $this->settings($layout['settings'] ?? null) : null;
        $this->requireAvailable(is_array($layout) && ($layout['version'] ?? null) === self::VERSION && $settings !== null
            && is_array($facts) && ErpCommercialSnapshotVersions::valid($facts)
            && ($layout['layout_id'] ?? null) === $sale->erp_invoice_layout_id
            && ($facts['company']['id'] ?? null) === $sale->erp_company_id
            && ($facts['document']['id'] ?? null) === $sale->id
            && ($facts['document']['invoice_no'] ?? null) === $sale->invoice_no
            && is_array($facts['lines'] ?? null) && $facts['lines'] !== []
            && is_array($facts['totals'] ?? null) && is_array($facts['establishment'] ?? null)
            && in_array($sale->status, ['draft', 'posted'], true)
            && ($sale->status !== 'posted' || isset($facts['accepted'])));

        $rows = [];
        foreach ($facts['lines'] as $line) {
            $this->requireAvailable(is_array($line) && is_string($line['product_snapshot']['description'] ?? null));
            $rows[] = [
                'description' => $line['product_snapshot']['description'],
                'quantity' => $this->decimal($line['quantity'] ?? null, 0),
                'unit_price' => $this->decimal($line['unit_price'] ?? null),
                'line_total' => $this->decimal($line['line_total'] ?? null),
            ];
        }
        $totals = [];
        foreach (['subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
            $totals[$field] = $this->decimal($facts['totals'][$field] ?? null);
        }

        return [
            'labels' => $settings['labels'], 'settings' => $settings,
            'company' => $facts['company'], 'establishment' => $facts['establishment'],
            'party' => $facts['party'] ?? null, 'number' => $facts['document']['invoice_no'],
            'currency' => $facts['currency'] ?? '', 'status' => $sale->status === 'posted' ? 'Venda lançada' : 'Rascunho',
            'rows' => $rows, 'totals' => $totals,
        ];
    }

    /** @return array<string, mixed>|null */
    private function settings(mixed $input): ?array
    {
        if (! is_array($input) || ($input['version'] ?? null) !== self::VERSION || ! is_array($input['labels'] ?? null)
            || ! is_bool($input['show_customer'] ?? null) || ! array_key_exists('footer_text', $input)
            || ($input['footer_text'] !== null && (! is_string($input['footer_text']) || mb_strlen($input['footer_text']) > 1000))) {
            return null;
        }
        $labels = [];
        foreach (array_keys(self::DEFAULT_SETTINGS['labels']) as $key) {
            $value = $input['labels'][$key] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 100) {
                return null;
            }
            $labels[$key] = $value;
        }

        return ['version' => self::VERSION, 'labels' => $labels, 'show_customer' => $input['show_customer'], 'footer_text' => $input['footer_text']];
    }

    private function decimal(mixed $value, int $minimumFraction = 2): string
    {
        $this->requireAvailable(is_string($value) && preg_match('/^\d{1,18}(?:\.\d{1,4})?$/D', $value) === 1);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(rtrim($fraction, '0'), $minimumFraction, '0');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);

        return $whole.($fraction === '' ? '' : ','.$fraction);
    }

    private function requireAvailable(bool $condition): void
    {
        abort_unless($condition, response()->json([
            'code' => 'commercial_representation_unavailable',
            'message' => 'Esta venda não possui uma representação comercial preservada compatível. Consulte os dados originais da venda.',
        ], 409));
    }
}
