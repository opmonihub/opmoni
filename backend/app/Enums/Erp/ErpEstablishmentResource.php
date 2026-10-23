<?php

namespace App\Enums\Erp;

use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use App\Models\Erp\Sales\ErpOrder;
use App\Models\Erp\Sales\ErpQuote;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Models\Erp\Stock\ErpStockAdjustment;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\Erp\Stock\ErpStockTransfer;

enum ErpEstablishmentResource: string
{
    case Establishment = 'establishment';
    case TaxRate = 'tax_rate';
    case OperationNature = 'operation_nature';
    case StockBalance = 'stock_balance';
    case StockMovement = 'stock_movement';
    case StockLot = 'stock_lot';
    case StockAdjustment = 'stock_adjustment';
    case StockTransfer = 'stock_transfer';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case Quote = 'quote';
    case Order = 'order';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case FinancialAccount = 'financial_account';
    case Obligation = 'obligation';
    case Payment = 'payment';
    case CashRegister = 'cash_register';
    case CashSession = 'cash_session';
    case FiscalCertificate = 'fiscal_certificate';
    case FiscalDocument = 'fiscal_document';
    case FiscalEvent = 'fiscal_event';
    case FiscalInvalidation = 'fiscal_invalidation';
    case AccountingDelivery = 'accounting_delivery';
    case AccountingMapping = 'accounting_mapping';

    /** @param class-string $modelClass */
    public static function forModel(string $modelClass): ?self
    {
        return match ($modelClass) {
            ErpEstablishment::class => self::Establishment,
            ErpTaxRate::class => self::TaxRate,
            ErpOperationNature::class => self::OperationNature,
            ErpStockBalance::class => self::StockBalance,
            ErpStockMovement::class => self::StockMovement,
            ErpStockLot::class => self::StockLot,
            ErpStockAdjustment::class => self::StockAdjustment,
            ErpStockTransfer::class => self::StockTransfer,
            ErpPurchase::class => self::Purchase,
            ErpPurchaseReturn::class => self::PurchaseReturn,
            ErpQuote::class => self::Quote,
            ErpOrder::class => self::Order,
            ErpSale::class => self::Sale,
            ErpSaleReturn::class => self::SaleReturn,
            ErpFinancialAccount::class => self::FinancialAccount,
            ErpObligation::class => self::Obligation,
            ErpPayment::class => self::Payment,
            ErpCashRegister::class => self::CashRegister,
            ErpCashSession::class => self::CashSession,
            ErpFiscalCertificate::class => self::FiscalCertificate,
            ErpFiscalDocument::class => self::FiscalDocument,
            ErpFiscalEvent::class => self::FiscalEvent,
            ErpFiscalInvalidation::class => self::FiscalInvalidation,
            ErpAccountingDelivery::class => self::AccountingDelivery,
            ErpAccountingMapping::class => self::AccountingMapping,
            default => null,
        };
    }
}
