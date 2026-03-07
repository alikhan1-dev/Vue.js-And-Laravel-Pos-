<?php

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController as ApiAuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\RegisteredUserController as ApiRegisteredUserController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PaymentController;
use App\Models\PaymentMethod;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\WarehouseStockController;
use App\Http\Controllers\Api\WarrantyLookupController;
use App\Http\Controllers\Api\WarrantyClaimController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SupplierInvoiceController;
use App\Http\Controllers\Api\SupplierPaymentController;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    // Tenant-scoped: only branches of the logged-in user's company
    Route::get('/branches', function () {
        return Branch::with('company:id,name')->get();
    });
    // Tenant-scoped: only warehouses whose branch belongs to the user's company
    Route::get('/warehouses', function () {
        return Warehouse::with('branch:id,name,code')->get();
    });

    // Products (tenant-aware)
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{id}/stock', [ProductController::class, 'stock']);

    // Categories, brands, units (master data)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::post('/brands', [BrandController::class, 'store']);
    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);

    // Stock movements (tenant-aware, filterable)
    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::post('/stock-movements', [StockMovementController::class, 'store']);

    // Warehouse stock report and inter-warehouse transfers
    Route::get('/warehouse-stock', [WarehouseStockController::class, 'index']);
    Route::post('/transfers', [TransferController::class, 'store']);

    // Sales & quotations (tenant-aware)
    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{id}', [SaleController::class, 'show']);
    Route::post('/sales/{id}/convert', [SaleController::class, 'convert']);
    Route::post('/sales/{id}/return', [SaleController::class, 'returnSale']);
    Route::post('/sales/{id}/complete', [SaleController::class, 'complete']);
    Route::post('/sales/{id}/cancel', [SaleController::class, 'cancel']);
    Route::get('/sales/{id}/stock-check', [SaleController::class, 'stockCheck']);

    // Customers (Sales & Customer Engine)
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{id}/warranties', [WarrantyLookupController::class, 'customerWarranties']);
    Route::get('/customers/{id}', [CustomerController::class, 'show']);
    Route::put('/customers/{id}', [CustomerController::class, 'update']);

    // Warranty management
    Route::get('/warranty/lookup', [WarrantyLookupController::class, 'lookup']);
    Route::get('/warranty-claims', [WarrantyClaimController::class, 'index']);
    Route::post('/warranty-claims', [WarrantyClaimController::class, 'store']);
    Route::put('/warranty-claims/{id}', [WarrantyClaimController::class, 'update']);

    // Payments & accounting (tenant-aware)
    Route::get('/accounts', function () {
        return \App\Models\Account::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type']);
    });
    Route::get('/payment-methods', function () {
        return PaymentMethod::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']);
    });
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{id}', [PaymentController::class, 'show']);
    Route::post('/payments/{id}/refund', [PaymentController::class, 'refund']);

    // Purchase & Supplier Engine (Step 7)
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases/{id}', [PurchaseController::class, 'show']);
    Route::post('/purchases/{id}/confirm', [PurchaseController::class, 'confirm']);
    Route::post('/purchases/{id}/mark-ordered', [PurchaseController::class, 'markOrdered']);
    Route::post('/purchases/{id}/receive', [PurchaseController::class, 'receive']);
    Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index']);
    Route::post('/supplier-invoices', [SupplierInvoiceController::class, 'store']);
    Route::post('/supplier-invoices/{id}/post', [SupplierInvoiceController::class, 'post']);
    Route::post('/supplier-payments', [SupplierPaymentController::class, 'store']);
});

Route::prefix('auth')->group(function () {
    Route::post('register', [ApiRegisteredUserController::class, 'store']);
    Route::post('login', [ApiAuthenticatedSessionController::class, 'store']);
    Route::post('logout', [ApiAuthenticatedSessionController::class, 'destroy'])
        ->middleware('auth:sanctum');
});
