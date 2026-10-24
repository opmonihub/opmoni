<?php

namespace App\Erp;

final class ErpCompanyPermissions
{
    /** @var array<string, bool> Explicit ordinary actions; owner/platform administration is excluded. */
    private const ACTIONS = [
        'erp.read' => false,
        'erp.establishments.index' => false,
        'erp.establishments.show' => false,
        'erp.establishments.store' => true,
        'erp.establishments.update' => true,
        'erp.company.settings.show' => false,
        'erp.company.settings.update' => true,
        'erp.contacts.index' => false,
        'erp.contacts.store' => true,
        'erp.contacts.show' => false,
        'erp.contacts.update' => true,
        'erp.contacts.archive' => true,
        'erp.contacts.notes.index' => false,
        'erp.contacts.notes.store' => true,
        'erp.contacts.notes.show' => false,
        'erp.contacts.notes.update' => true,
        'erp.contacts.notes.archive' => true,
        'erp.contacts.notes.attachments.store' => true,
        'erp.contacts.notes.attachments.download' => false,
        'erp.contacts.notes.attachments.archive' => true,
        'erp.products.index' => false,
        'erp.products.store' => true,
        'erp.products.show' => false,
        'erp.products.update' => true,
        'erp.products.archive' => true,
        'erp.product-tax-profiles.index' => false,
        'erp.product-tax-profiles.store' => true,
        'erp.product-tax-profiles.update' => true,
        'erp.product-tax-profiles.destroy' => true,
        'erp.units.index' => false,
        'erp.units.store' => true,
        'erp.units.show' => false,
        'erp.units.update' => true,
        'erp.units.archive' => true,
        'erp.categories.index' => false,
        'erp.categories.store' => true,
        'erp.categories.show' => false,
        'erp.categories.update' => true,
        'erp.categories.archive' => true,
        'erp.brands.index' => false,
        'erp.brands.store' => true,
        'erp.brands.show' => false,
        'erp.brands.update' => true,
        'erp.brands.archive' => true,
        'erp.price-groups.index' => false,
        'erp.price-groups.store' => true,
        'erp.price-groups.show' => false,
        'erp.price-groups.update' => true,
        'erp.price-groups.archive' => true,
        'erp.customer-groups.index' => false,
        'erp.customer-groups.store' => true,
        'erp.customer-groups.show' => false,
        'erp.customer-groups.update' => true,
        'erp.customer-groups.archive' => true,
        'erp.discount-rules.index' => false,
        'erp.discount-rules.store' => true,
        'erp.discount-rules.show' => false,
        'erp.discount-rules.update' => true,
        'erp.discount-rules.archive' => true,
        'erp.tax-rates.index' => false,
        'erp.tax-rates.store' => true,
        'erp.tax-rates.show' => false,
        'erp.tax-rates.update' => true,
        'erp.tax-rates.archive' => true,
        'erp.operation-natures.index' => false,
        'erp.operation-natures.store' => true,
        'erp.operation-natures.show' => false,
        'erp.operation-natures.update' => true,
        'erp.operation-natures.archive' => true,
        'erp.carriers.index' => false,
        'erp.carriers.store' => true,
        'erp.carriers.show' => false,
        'erp.carriers.update' => true,
        'erp.carriers.archive' => true,
        'erp.vehicles.index' => false,
        'erp.vehicles.store' => true,
        'erp.vehicles.show' => false,
        'erp.vehicles.update' => true,
        'erp.vehicles.archive' => true,
        'erp.drivers.index' => false,
        'erp.drivers.store' => true,
        'erp.drivers.show' => false,
        'erp.drivers.update' => true,
        'erp.drivers.archive' => true,
        'erp.catalog.import.products.preview' => true,
        'erp.catalog.import.products.read' => true,
        'erp.catalog.import.products.confirm' => true,
        'erp.catalog.import.contacts.preview' => true,
        'erp.catalog.import.contacts.read' => true,
        'erp.catalog.import.contacts.confirm' => true,
        'erp.catalog.print-settings.show' => false,
        'erp.catalog.print-settings.update' => true,
        'erp.catalog.product-labels' => true,
        'erp.catalog.label-layouts.index' => false,
        'erp.catalog.label-layouts.store' => true,
        'erp.catalog.label-layouts.show' => false,
        'erp.catalog.label-layouts.update' => true,
        'erp.catalog.label-layouts.default' => true,
        'erp.catalog.label-layouts.archive' => true,
        'erp.catalog.printer-profiles.index' => false,
        'erp.catalog.printer-profiles.store' => true,
        'erp.catalog.printer-profiles.show' => false,
        'erp.catalog.printer-profiles.update' => true,
        'erp.catalog.printer-profiles.archive' => true,
        'erp.financial-accounts.index' => false,
        'erp.financial-accounts.store' => true,
        'erp.financial-accounts.show' => false,
        'erp.obligations.index' => false,
        'erp.obligations.store' => true,
        'erp.obligations.show' => false,
        'erp.payments.index' => false,
        'erp.payments.store' => true,
        'erp.payments.show' => false,
        'erp.payments.allocate' => true,
        'erp.payments.allocations.index' => false,
        'erp.payments.receipt' => false,
        'erp.payments.reverse' => true,
        'erp.payment-allocations.reverse' => true,
        'erp.cash-registers.index' => false,
        'erp.cash-registers.store' => true,
        'erp.cash-registers.show' => false,
        'erp.cash-sessions.index' => false,
        'erp.cash-sessions.store' => true,
        'erp.cash-sessions.show' => false,
        'erp.cash-sessions.close' => true,
        'erp.cash-sessions.movements.index' => false,
        'erp.cash-sessions.movements.store' => true,
        'erp.purchases.index' => false,
        'erp.purchases.store' => true,
        'erp.purchases.xml-preview' => true,
        'erp.purchases.xml-preview.read' => true,
        'erp.purchases.xml-confirm' => true,
        'erp.purchases.update' => true,
        'erp.purchases.show' => false,
        'erp.purchases.post' => true,
        'erp.purchase-returns.index' => false,
        'erp.purchase-returns.store' => true,
        'erp.purchase-returns.show' => false,
        'erp.purchase-returns.post' => true,
        'erp.stock.balances.index' => false,
        'erp.stock.movements.index' => false,
        'erp.stock.movements.show' => false,
        'erp.stock.oversell' => true,
        'erp.stock.lots.index' => false,
        'erp.stock.movements.reverse' => true,
        'erp.stock.transfers.show' => false,
        'erp.stock.transfers.reverse' => true,
        'erp.stock.adjustments.index' => false,
        'erp.stock.adjustments.show' => false,
        'erp.stock.transfers.index' => false,
        'erp.stock.adjustments.store' => true,
        'erp.stock.transfers.store' => true,
        'erp.sales.price.override' => true,
        'erp.sales.discount.override' => true,
        'erp.quotes.update' => true,
        'erp.orders.update' => true,
        'erp.orders.convert' => true,
        'erp.sales.update' => true,
        'erp.quotes.index' => false,
        'erp.quotes.store' => true,
        'erp.quotes.show' => false,
        'erp.quotes.convert' => true,
        'erp.orders.index' => false,
        'erp.orders.store' => true,
        'erp.orders.show' => false,
        'erp.sales.index' => false,
        'erp.sales.store' => true,
        'erp.sales.show' => false,
        'erp.sales.representation' => false,
        'erp.sales.post' => true,
        'erp.sale-returns.index' => false,
        'erp.sale-returns.store' => true,
        'erp.sale-returns.show' => false,
        'erp.sale-returns.post' => true,
        'erp.fiscal.documents.index' => false,
        'erp.fiscal.documents.store' => true,
        'erp.fiscal.documents.show' => false,
        'erp.fiscal.documents.queue' => true,
        'erp.fiscal.documents.transmit' => true,
        'erp.fiscal.documents.consult' => true,
        'erp.fiscal.documents.contingency' => true,
        'erp.fiscal.documents.reconcile-contingency' => true,
        'erp.fiscal.documents.xml' => false,
        'erp.fiscal.documents.pdf' => false,
        'erp.fiscal.configuration.show' => false,
        'erp.fiscal.configuration.update' => true,
        'erp.fiscal.certificates.index' => false,
        'erp.fiscal.certificates.show' => false,
        'erp.fiscal.certificates.store' => true,
        'erp.fiscal.certificates.rotate' => true,
        'erp.fiscal.events.index' => false,
        'erp.fiscal.events.store' => true,
        'erp.fiscal.events.show' => false,
        'erp.fiscal.events.submit' => true,
        'erp.fiscal.invalidations.index' => false,
        'erp.fiscal.invalidations.store' => true,
        'erp.fiscal.invalidations.show' => false,
        'erp.fiscal.invalidations.submit' => true,
        'erp.accounting-mappings.index' => false,
        'erp.accounting-deliveries.store' => true,
        'erp.accounting-deliveries.index' => false,
        'erp.accounting-deliveries.show' => false,
        'erp.accounting-deliveries.retry' => true,
        'erp.reports.exports.show' => false,
        'erp.reports.show' => false,
        'erp.reports.exports.store' => true,
    ];

    /** @return list<string> */
    public static function delegatedNames(): array
    {
        return array_values(array_diff(self::names(), ['erp.accounting-mappings.index', 'erp.accounting-deliveries.store']));
    }

    /** @param list<string> $allowlist */
    public static function delegatedAllows(string $permission, bool $write, array $allowlist, ?string $officeRole): bool
    {
        if (! in_array($officeRole, ['admin', 'operador', 'user'], true)
            || ($write && $officeRole === 'user')
            || ! in_array($permission, self::delegatedNames(), true)
            || self::ACTIONS[$permission] !== $write) {
            return false;
        }
        if (str_starts_with($permission, 'erp.product-tax-profiles.') && ! in_array($permission, $allowlist, true)) {
            return false;
        }
        if ($permission === 'erp.read') {
            return in_array('erp.read', $allowlist, true) || in_array('*', $allowlist, true);
        }
        $family = preg_replace('/\.[^.]+$/', '', $permission);
        foreach (['*', $permission, $family.'.*', $family.($write ? '.write' : '.read'), $write ? 'erp.write' : 'erp.read'] as $candidate) {
            if (in_array($candidate, $allowlist, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $allowlist @return list<string> */
    public static function delegatedEffective(array $allowlist, ?string $officeRole): array
    {
        return array_values(array_filter(self::delegatedNames(), fn (string $permission): bool => self::delegatedAllows($permission, self::ACTIONS[$permission], $allowlist, $officeRole)));
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ACTIONS);
    }

    /** @return list<array{permission: string, label: string, write: bool}> */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::ACTIONS as $permission => $write) {
            $catalog[] = ['permission' => $permission, 'label' => $permission, 'write' => $write];
        }

        return $catalog;
    }
}
