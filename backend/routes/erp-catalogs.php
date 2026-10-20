<?php

use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpBrandController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCarrierController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCatalogImportController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCatalogPrintController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCatalogPrinterProfileController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCatalogPrintLayoutController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCategoryController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpContactController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCustomerGroupController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpDiscountRuleController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpDriverController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpOperationNatureController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpPriceGroupController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpProductController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpTaxRateController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpUnitController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpVehicleController;
use App\Http\Controllers\Api\V1\Erp\ErpContactNoteController;
use Illuminate\Support\Facades\Route;

Route::get('/contacts', [ErpContactController::class, 'index'])->name('erp.contacts.index');
Route::post('/contacts', [ErpContactController::class, 'store'])->name('erp.contacts.store');
Route::get('/contacts/{contact}', [ErpContactController::class, 'show'])->whereNumber('contact')->name('erp.contacts.show');
Route::patch('/contacts/{contact}', [ErpContactController::class, 'update'])->whereNumber('contact')->name('erp.contacts.update');
Route::delete('/contacts/{contact}', [ErpContactController::class, 'archive'])->whereNumber('contact')->name('erp.contacts.archive');

Route::get('/contacts/{contact}/notes', [ErpContactNoteController::class, 'index'])->whereNumber('contact')->name('erp.contacts.notes.index');
Route::post('/contacts/{contact}/notes', [ErpContactNoteController::class, 'store'])->whereNumber('contact')->name('erp.contacts.notes.store');
Route::get('/contacts/{contact}/notes/{note}', [ErpContactNoteController::class, 'show'])->whereNumber('contact')->whereNumber('note')->name('erp.contacts.notes.show');
Route::patch('/contacts/{contact}/notes/{note}', [ErpContactNoteController::class, 'update'])->whereNumber('contact')->whereNumber('note')->name('erp.contacts.notes.update');
Route::delete('/contacts/{contact}/notes/{note}', [ErpContactNoteController::class, 'archive'])->whereNumber('contact')->whereNumber('note')->name('erp.contacts.notes.archive');
Route::post('/contacts/{contact}/notes/{note}/attachments', [ErpContactNoteController::class, 'storeAttachment'])->whereNumber('contact')->whereNumber('note')->name('erp.contacts.notes.attachments.store');
Route::get('/contacts/{contact}/notes/{note}/attachments/{attachment}/download', [ErpContactNoteController::class, 'downloadAttachment'])->whereNumber('contact')->whereNumber('note')->whereNumber('attachment')->name('erp.contacts.notes.attachments.download');
Route::delete('/contacts/{contact}/notes/{note}/attachments/{attachment}', [ErpContactNoteController::class, 'archiveAttachment'])->whereNumber('contact')->whereNumber('note')->whereNumber('attachment')->name('erp.contacts.notes.attachments.archive');

Route::get('/products', [ErpProductController::class, 'index'])->name('erp.products.index');
Route::post('/products', [ErpProductController::class, 'store'])->name('erp.products.store');
Route::get('/products/{product}', [ErpProductController::class, 'show'])->whereNumber('product')->name('erp.products.show');
Route::patch('/products/{product}', [ErpProductController::class, 'update'])->whereNumber('product')->name('erp.products.update');
Route::delete('/products/{product}', [ErpProductController::class, 'archive'])->whereNumber('product')->name('erp.products.archive');

Route::get('/units', [ErpUnitController::class, 'index'])->name('erp.units.index');
Route::post('/units', [ErpUnitController::class, 'store'])->name('erp.units.store');
Route::get('/units/{unit}', [ErpUnitController::class, 'show'])->whereNumber('unit')->name('erp.units.show');
Route::patch('/units/{unit}', [ErpUnitController::class, 'update'])->whereNumber('unit')->name('erp.units.update');
Route::delete('/units/{unit}', [ErpUnitController::class, 'archive'])->whereNumber('unit')->name('erp.units.archive');

Route::get('/categories', [ErpCategoryController::class, 'index'])->name('erp.categories.index');
Route::post('/categories', [ErpCategoryController::class, 'store'])->name('erp.categories.store');
Route::get('/categories/{category}', [ErpCategoryController::class, 'show'])->whereNumber('category')->name('erp.categories.show');
Route::patch('/categories/{category}', [ErpCategoryController::class, 'update'])->whereNumber('category')->name('erp.categories.update');
Route::delete('/categories/{category}', [ErpCategoryController::class, 'archive'])->whereNumber('category')->name('erp.categories.archive');

Route::get('/brands', [ErpBrandController::class, 'index'])->name('erp.brands.index');
Route::post('/brands', [ErpBrandController::class, 'store'])->name('erp.brands.store');
Route::get('/brands/{brand}', [ErpBrandController::class, 'show'])->whereNumber('brand')->name('erp.brands.show');
Route::patch('/brands/{brand}', [ErpBrandController::class, 'update'])->whereNumber('brand')->name('erp.brands.update');
Route::delete('/brands/{brand}', [ErpBrandController::class, 'archive'])->whereNumber('brand')->name('erp.brands.archive');

Route::get('/price-groups', [ErpPriceGroupController::class, 'index'])->name('erp.price-groups.index');
Route::post('/price-groups', [ErpPriceGroupController::class, 'store'])->name('erp.price-groups.store');
Route::get('/price-groups/{priceGroup}', [ErpPriceGroupController::class, 'show'])->whereNumber('priceGroup')->name('erp.price-groups.show');
Route::patch('/price-groups/{priceGroup}', [ErpPriceGroupController::class, 'update'])->whereNumber('priceGroup')->name('erp.price-groups.update');
Route::delete('/price-groups/{priceGroup}', [ErpPriceGroupController::class, 'archive'])->whereNumber('priceGroup')->name('erp.price-groups.archive');

Route::get('/customer-groups', [ErpCustomerGroupController::class, 'index'])->name('erp.customer-groups.index');
Route::post('/customer-groups', [ErpCustomerGroupController::class, 'store'])->name('erp.customer-groups.store');
Route::get('/customer-groups/{customerGroup}', [ErpCustomerGroupController::class, 'show'])->whereNumber('customerGroup')->name('erp.customer-groups.show');
Route::patch('/customer-groups/{customerGroup}', [ErpCustomerGroupController::class, 'update'])->whereNumber('customerGroup')->name('erp.customer-groups.update');
Route::delete('/customer-groups/{customerGroup}', [ErpCustomerGroupController::class, 'archive'])->whereNumber('customerGroup')->name('erp.customer-groups.archive');

Route::get('/discount-rules', [ErpDiscountRuleController::class, 'index'])->name('erp.discount-rules.index');
Route::post('/discount-rules', [ErpDiscountRuleController::class, 'store'])->name('erp.discount-rules.store');
Route::get('/discount-rules/{discountRule}', [ErpDiscountRuleController::class, 'show'])->whereNumber('discountRule')->name('erp.discount-rules.show');
Route::patch('/discount-rules/{discountRule}', [ErpDiscountRuleController::class, 'update'])->whereNumber('discountRule')->name('erp.discount-rules.update');
Route::delete('/discount-rules/{discountRule}', [ErpDiscountRuleController::class, 'archive'])->whereNumber('discountRule')->name('erp.discount-rules.archive');

Route::get('/tax-settings/tax-rates', [ErpTaxRateController::class, 'index'])->name('erp.tax-rates.index');
Route::post('/tax-settings/tax-rates', [ErpTaxRateController::class, 'store'])->name('erp.tax-rates.store');
Route::get('/tax-settings/tax-rates/{taxRate}', [ErpTaxRateController::class, 'show'])->whereNumber('taxRate')->name('erp.tax-rates.show');
Route::patch('/tax-settings/tax-rates/{taxRate}', [ErpTaxRateController::class, 'update'])->whereNumber('taxRate')->name('erp.tax-rates.update');
Route::delete('/tax-settings/tax-rates/{taxRate}', [ErpTaxRateController::class, 'archive'])->whereNumber('taxRate')->name('erp.tax-rates.archive');

Route::get('/tax-settings/operation-natures', [ErpOperationNatureController::class, 'index'])->name('erp.operation-natures.index');
Route::post('/tax-settings/operation-natures', [ErpOperationNatureController::class, 'store'])->name('erp.operation-natures.store');
Route::get('/tax-settings/operation-natures/{operationNature}', [ErpOperationNatureController::class, 'show'])->whereNumber('operationNature')->name('erp.operation-natures.show');
Route::patch('/tax-settings/operation-natures/{operationNature}', [ErpOperationNatureController::class, 'update'])->whereNumber('operationNature')->name('erp.operation-natures.update');
Route::delete('/tax-settings/operation-natures/{operationNature}', [ErpOperationNatureController::class, 'archive'])->whereNumber('operationNature')->name('erp.operation-natures.archive');

Route::get('/carriers', [ErpCarrierController::class, 'index'])->name('erp.carriers.index');
Route::post('/carriers', [ErpCarrierController::class, 'store'])->name('erp.carriers.store');
Route::get('/carriers/{carrier}', [ErpCarrierController::class, 'show'])->whereNumber('carrier')->name('erp.carriers.show');
Route::patch('/carriers/{carrier}', [ErpCarrierController::class, 'update'])->whereNumber('carrier')->name('erp.carriers.update');
Route::delete('/carriers/{carrier}', [ErpCarrierController::class, 'archive'])->whereNumber('carrier')->name('erp.carriers.archive');

Route::get('/vehicles', [ErpVehicleController::class, 'index'])->name('erp.vehicles.index');
Route::post('/vehicles', [ErpVehicleController::class, 'store'])->name('erp.vehicles.store');
Route::get('/vehicles/{vehicle}', [ErpVehicleController::class, 'show'])->whereNumber('vehicle')->name('erp.vehicles.show');
Route::patch('/vehicles/{vehicle}', [ErpVehicleController::class, 'update'])->whereNumber('vehicle')->name('erp.vehicles.update');
Route::delete('/vehicles/{vehicle}', [ErpVehicleController::class, 'archive'])->whereNumber('vehicle')->name('erp.vehicles.archive');

Route::get('/drivers', [ErpDriverController::class, 'index'])->name('erp.drivers.index');
Route::post('/drivers', [ErpDriverController::class, 'store'])->name('erp.drivers.store');
Route::get('/drivers/{driver}', [ErpDriverController::class, 'show'])->whereNumber('driver')->name('erp.drivers.show');
Route::patch('/drivers/{driver}', [ErpDriverController::class, 'update'])->whereNumber('driver')->name('erp.drivers.update');
Route::delete('/drivers/{driver}', [ErpDriverController::class, 'archive'])->whereNumber('driver')->name('erp.drivers.archive');

Route::post('/catalog/import/products/preview', [ErpCatalogImportController::class, 'previewProducts'])->name('erp.catalog.import.products.preview');
Route::post('/catalog/import/products/confirm', [ErpCatalogImportController::class, 'confirmProducts'])->name('erp.catalog.import.products.confirm');
Route::post('/catalog/import/contacts/preview', [ErpCatalogImportController::class, 'previewContacts'])->name('erp.catalog.import.contacts.preview');
Route::post('/catalog/import/contacts/confirm', [ErpCatalogImportController::class, 'confirmContacts'])->name('erp.catalog.import.contacts.confirm');

Route::get('/catalog/print-settings', [ErpCatalogPrintController::class, 'showSettings'])->name('erp.catalog.print-settings.show');
Route::patch('/catalog/print-settings', [ErpCatalogPrintController::class, 'updateSettings'])->name('erp.catalog.print-settings.update');
Route::post('/catalog/product-labels', [ErpCatalogPrintController::class, 'productLabels'])->name('erp.catalog.product-labels');
Route::get('/catalog/label-layouts', [ErpCatalogPrintLayoutController::class, 'index'])->name('erp.catalog.label-layouts.index');
Route::post('/catalog/label-layouts', [ErpCatalogPrintLayoutController::class, 'store'])->name('erp.catalog.label-layouts.store');
Route::get('/catalog/label-layouts/{layout}', [ErpCatalogPrintLayoutController::class, 'show'])->whereNumber('layout')->name('erp.catalog.label-layouts.show');
Route::patch('/catalog/label-layouts/{layout}', [ErpCatalogPrintLayoutController::class, 'update'])->whereNumber('layout')->name('erp.catalog.label-layouts.update');
Route::patch('/catalog/label-layouts/{layout}/default', [ErpCatalogPrintLayoutController::class, 'makeDefault'])->whereNumber('layout')->name('erp.catalog.label-layouts.default');
Route::delete('/catalog/label-layouts/{layout}', [ErpCatalogPrintLayoutController::class, 'archive'])->whereNumber('layout')->name('erp.catalog.label-layouts.archive');
Route::get('/catalog/printer-profiles', [ErpCatalogPrinterProfileController::class, 'index'])->name('erp.catalog.printer-profiles.index');
Route::post('/catalog/printer-profiles', [ErpCatalogPrinterProfileController::class, 'store'])->name('erp.catalog.printer-profiles.store');
Route::get('/catalog/printer-profiles/{profile}', [ErpCatalogPrinterProfileController::class, 'show'])->whereNumber('profile')->name('erp.catalog.printer-profiles.show');
Route::patch('/catalog/printer-profiles/{profile}', [ErpCatalogPrinterProfileController::class, 'update'])->whereNumber('profile')->name('erp.catalog.printer-profiles.update');
Route::delete('/catalog/printer-profiles/{profile}', [ErpCatalogPrinterProfileController::class, 'archive'])->whereNumber('profile')->name('erp.catalog.printer-profiles.archive');
