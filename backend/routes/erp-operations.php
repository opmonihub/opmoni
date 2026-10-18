<?php

use App\Http\Controllers\Api\V1\Erp\Finance\ErpCashRegisterController;
use App\Http\Controllers\Api\V1\Erp\Finance\ErpCashSessionController;
use App\Http\Controllers\Api\V1\Erp\Finance\ErpFinancialAccountController;
use App\Http\Controllers\Api\V1\Erp\Finance\ErpObligationController;
use App\Http\Controllers\Api\V1\Erp\Finance\ErpPaymentController;
use App\Http\Controllers\Api\V1\Erp\Fiscal\ErpFiscalCertificateController;
use App\Http\Controllers\Api\V1\Erp\Fiscal\ErpFiscalDocumentController;
use App\Http\Controllers\Api\V1\Erp\Fiscal\ErpFiscalEventController;
use App\Http\Controllers\Api\V1\Erp\Fiscal\ErpFiscalInvalidationController;
use App\Http\Controllers\Api\V1\Erp\Fiscal\ErpFiscalOperatorCanaryController;
use App\Http\Controllers\Api\V1\Erp\Sales\ErpOrderController;
use App\Http\Controllers\Api\V1\Erp\Sales\ErpQuoteController;
use App\Http\Controllers\Api\V1\Erp\Sales\ErpSaleController;
use App\Http\Controllers\Api\V1\Erp\Sales\ErpSaleReturnController;
use App\Http\Controllers\Api\V1\Erp\Stock\ErpPurchaseController;
use App\Http\Controllers\Api\V1\Erp\Stock\ErpPurchaseReturnController;
use App\Http\Controllers\Api\V1\Erp\Stock\ErpPurchaseXmlPreviewController;
use App\Http\Controllers\Api\V1\Erp\Stock\ErpStockController;
use Illuminate\Support\Facades\Route;

Route::get('/financial-accounts', [ErpFinancialAccountController::class, 'index'])->name('erp.financial-accounts.index');
Route::post('/financial-accounts', [ErpFinancialAccountController::class, 'store'])->name('erp.financial-accounts.store');
Route::get('/financial-accounts/{financialAccount}', [ErpFinancialAccountController::class, 'show'])->whereNumber('financialAccount')->name('erp.financial-accounts.show');

Route::get('/obligations', [ErpObligationController::class, 'index'])->name('erp.obligations.index');
Route::post('/obligations', [ErpObligationController::class, 'store'])->name('erp.obligations.store');
Route::get('/obligations/{obligation}', [ErpObligationController::class, 'show'])->whereNumber('obligation')->name('erp.obligations.show');

Route::get('/payments', [ErpPaymentController::class, 'index'])->name('erp.payments.index');
Route::post('/payments', [ErpPaymentController::class, 'store'])->name('erp.payments.store');
Route::get('/payments/{payment}', [ErpPaymentController::class, 'show'])->whereNumber('payment')->name('erp.payments.show');
Route::post('/payments/{payment}/allocate', [ErpPaymentController::class, 'allocate'])->whereNumber('payment')->name('erp.payments.allocate');

Route::get('/cash-registers', [ErpCashRegisterController::class, 'index'])->name('erp.cash-registers.index');
Route::post('/cash-registers', [ErpCashRegisterController::class, 'store'])->name('erp.cash-registers.store');
Route::get('/cash-registers/{cashRegister}', [ErpCashRegisterController::class, 'show'])->whereNumber('cashRegister')->name('erp.cash-registers.show');

Route::get('/cash-sessions', [ErpCashSessionController::class, 'index'])->name('erp.cash-sessions.index');
Route::post('/cash-sessions', [ErpCashSessionController::class, 'store'])->name('erp.cash-sessions.store');
Route::get('/cash-sessions/{cashSession}', [ErpCashSessionController::class, 'show'])->whereNumber('cashSession')->name('erp.cash-sessions.show');
Route::post('/cash-sessions/{cashSession}/close', [ErpCashSessionController::class, 'close'])->whereNumber('cashSession')->name('erp.cash-sessions.close');

Route::get('/purchases', [ErpPurchaseController::class, 'index'])->name('erp.purchases.index');
Route::post('/purchases', [ErpPurchaseController::class, 'store'])->name('erp.purchases.store');
Route::post('/purchases/xml-preview', [ErpPurchaseXmlPreviewController::class, 'store'])->name('erp.purchases.xml-preview');
Route::get('/purchases/{purchase}', [ErpPurchaseController::class, 'show'])->whereNumber('purchase')->name('erp.purchases.show');
Route::post('/purchases/{purchase}/post', [ErpPurchaseController::class, 'post'])->whereNumber('purchase')->name('erp.purchases.post');

Route::get('/purchase-returns', [ErpPurchaseReturnController::class, 'index'])->name('erp.purchase-returns.index');
Route::post('/purchase-returns', [ErpPurchaseReturnController::class, 'store'])->name('erp.purchase-returns.store');
Route::get('/purchase-returns/{purchaseReturn}', [ErpPurchaseReturnController::class, 'show'])->whereNumber('purchaseReturn')->name('erp.purchase-returns.show');
Route::post('/purchase-returns/{purchaseReturn}/post', [ErpPurchaseReturnController::class, 'post'])->whereNumber('purchaseReturn')->name('erp.purchase-returns.post');

Route::prefix('stock')->name('erp.stock.')->group(function (): void {
    Route::get('/balances', [ErpStockController::class, 'balances'])->name('balances.index');
    Route::get('/movements', [ErpStockController::class, 'movements'])->name('movements.index');
    Route::get('/adjustments', [ErpStockController::class, 'adjustments'])->name('adjustments.index');
    Route::get('/transfers', [ErpStockController::class, 'transfers'])->name('transfers.index');
    Route::post('/adjustments', [ErpStockController::class, 'storeAdjustment'])->name('adjustments.store');
    Route::post('/transfers', [ErpStockController::class, 'storeTransfer'])->name('transfers.store');
});

Route::get('/quotes', [ErpQuoteController::class, 'index'])->name('erp.quotes.index');
Route::post('/quotes', [ErpQuoteController::class, 'store'])->name('erp.quotes.store');
Route::get('/quotes/{quote}', [ErpQuoteController::class, 'show'])->whereNumber('quote')->name('erp.quotes.show');
Route::post('/quotes/{quote}/convert', [ErpQuoteController::class, 'convert'])->whereNumber('quote')->name('erp.quotes.convert');

Route::get('/orders', [ErpOrderController::class, 'index'])->name('erp.orders.index');
Route::post('/orders', [ErpOrderController::class, 'store'])->name('erp.orders.store');
Route::get('/orders/{order}', [ErpOrderController::class, 'show'])->whereNumber('order')->name('erp.orders.show');

Route::get('/sales', [ErpSaleController::class, 'index'])->name('erp.sales.index');
Route::post('/sales', [ErpSaleController::class, 'store'])->name('erp.sales.store');
Route::get('/sales/{sale}', [ErpSaleController::class, 'show'])->whereNumber('sale')->name('erp.sales.show');
Route::post('/sales/{sale}/post', [ErpSaleController::class, 'post'])->whereNumber('sale')->name('erp.sales.post');

Route::get('/sale-returns', [ErpSaleReturnController::class, 'index'])->name('erp.sale-returns.index');
Route::post('/sale-returns', [ErpSaleReturnController::class, 'store'])->name('erp.sale-returns.store');
Route::get('/sale-returns/{saleReturn}', [ErpSaleReturnController::class, 'show'])->whereNumber('saleReturn')->name('erp.sale-returns.show');
Route::post('/sale-returns/{saleReturn}/post', [ErpSaleReturnController::class, 'post'])->whereNumber('saleReturn')->name('erp.sale-returns.post');

Route::prefix('fiscal')->name('erp.fiscal.')->group(function (): void {
    Route::get('/documents', [ErpFiscalDocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [ErpFiscalDocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [ErpFiscalDocumentController::class, 'show'])->whereNumber('document')->name('documents.show');
    Route::post('/documents/{document}/queue', [ErpFiscalDocumentController::class, 'queue'])->whereNumber('document')->name('documents.queue');
    Route::post('/documents/{document}/transmit', [ErpFiscalDocumentController::class, 'transmit'])->whereNumber('document')->name('documents.transmit');
    Route::post('/documents/{document}/consult', [ErpFiscalDocumentController::class, 'consult'])->whereNumber('document')->name('documents.consult');
    Route::post('/documents/{document}/contingency', [ErpFiscalDocumentController::class, 'contingency'])->whereNumber('document')->name('documents.contingency');
    Route::post('/documents/{document}/reconcile-contingency', [ErpFiscalDocumentController::class, 'reconcileContingency'])->whereNumber('document')->name('documents.reconcile-contingency');
    Route::get('/documents/{document}/xml', [ErpFiscalDocumentController::class, 'downloadXml'])->whereNumber('document')->name('documents.xml');
    Route::get('/documents/{document}/pdf', [ErpFiscalDocumentController::class, 'downloadPdf'])->whereNumber('document')->name('documents.pdf');

    Route::get('/certificates', [ErpFiscalCertificateController::class, 'index'])->name('certificates.index');
    Route::post('/certificates', [ErpFiscalCertificateController::class, 'store'])->name('certificates.store');
    Route::get('/certificates/{certificate}', [ErpFiscalCertificateController::class, 'show'])->whereNumber('certificate')->name('certificates.show');
    Route::post('/certificates/{certificate}/rotate', [ErpFiscalCertificateController::class, 'rotate'])->whereNumber('certificate')->name('certificates.rotate');

    Route::get('/events', [ErpFiscalEventController::class, 'index'])->name('events.index');
    Route::post('/events', [ErpFiscalEventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [ErpFiscalEventController::class, 'show'])->whereNumber('event')->name('events.show');
    Route::post('/events/{event}/submit', [ErpFiscalEventController::class, 'submit'])->whereNumber('event')->name('events.submit');

    Route::get('/invalidations', [ErpFiscalInvalidationController::class, 'index'])->name('invalidations.index');
    Route::post('/invalidations', [ErpFiscalInvalidationController::class, 'store'])->name('invalidations.store');
    Route::get('/invalidations/{invalidation}', [ErpFiscalInvalidationController::class, 'show'])->whereNumber('invalidation')->name('invalidations.show');
    Route::post('/invalidations/{invalidation}/submit', [ErpFiscalInvalidationController::class, 'submit'])->whereNumber('invalidation')->name('invalidations.submit');

    Route::get('/operator-canary/status', [ErpFiscalOperatorCanaryController::class, 'status'])->name('operator-canary.status');
    Route::post('/operator-canary/documents/{document}/transmit', [ErpFiscalOperatorCanaryController::class, 'transmit'])->whereNumber('document')->name('operator-canary.transmit');
});
