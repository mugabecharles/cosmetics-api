<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\AuditLogController;

// ── Public ──────────────────────────────────────────────────────
Route::post('auth/login',    [AuthController::class, 'login']);

// ── Protected ───────────────────────────────────────────────────
Route::middleware('auth:api')->group(function () {

    // Auth
    Route::get   ('auth/me',              [AuthController::class, 'me']);
    Route::post  ('auth/logout',          [AuthController::class, 'logout']);
    Route::post  ('auth/refresh',         [AuthController::class, 'refresh']);
    Route::post  ('auth/change-password', [AuthController::class, 'changePassword']);

    // Users — admin only
    Route::middleware('role:administrator')->group(function () {
        Route::get   ('users',         [UserController::class, 'index']);
        Route::post  ('users',         [UserController::class, 'store']);
        Route::get   ('users/{user}',  [UserController::class, 'show']);
        Route::put   ('users/{user}',  [UserController::class, 'update']);
        Route::delete('users/{user}',  [UserController::class, 'destroy']);
        Route::get   ('roles',         [UserController::class, 'roles']);
        Route::get   ('audit-logs',    [AuditLogController::class, 'index']);
    });

    // Categories
    Route::get ('categories/all',           [CategoryController::class, 'all']);
    Route::get ('categories',               [CategoryController::class, 'index']);
    Route::post('categories',               [CategoryController::class, 'store']);
    Route::put ('categories/{category}',    [CategoryController::class, 'update']);
    Route::delete('categories/{category}',  [CategoryController::class, 'destroy']);

    // Brands
    Route::get ('brands/all',       [BrandController::class, 'all']);
    Route::get ('brands',           [BrandController::class, 'index']);
    Route::post('brands',           [BrandController::class, 'store']);
    Route::put ('brands/{brand}',   [BrandController::class, 'update']);
    Route::delete('brands/{brand}', [BrandController::class, 'destroy']);

    // Products
    Route::get ('products/all',                    [ProductController::class, 'all']);
    Route::get ('products/low-stock',              [ProductController::class, 'lowStock']);
    Route::get ('products/expiring',               [ProductController::class, 'expiring']);
    Route::get ('products',                        [ProductController::class, 'index']);
    Route::post('products',                        [ProductController::class, 'store']);
    Route::get ('products/{product}',              [ProductController::class, 'show']);
    Route::put ('products/{product}',              [ProductController::class, 'update']);
    Route::delete('products/{product}',            [ProductController::class, 'destroy']);
    Route::post('products/{product}/adjust-stock', [ProductController::class, 'adjust']);

    // Customers
    Route::get ('customers/all',                        [CustomerController::class, 'all']);
    Route::get ('customers',                            [CustomerController::class, 'index']);
    Route::post('customers',                            [CustomerController::class, 'store']);
    Route::get ('customers/{customer}',                 [CustomerController::class, 'show']);
    Route::put ('customers/{customer}',                 [CustomerController::class, 'update']);
    Route::get ('customers/{customer}/statement',       [CustomerController::class, 'statement']);
    Route::post('customers/{customer}/payment',         [CustomerController::class, 'recordPayment']);

    // Suppliers
    Route::get ('suppliers/all',                        [SupplierController::class, 'all']);
    Route::get ('suppliers',                            [SupplierController::class, 'index']);
    Route::post('suppliers',                            [SupplierController::class, 'store']);
    Route::get ('suppliers/{supplier}',                 [SupplierController::class, 'show']);
    Route::put ('suppliers/{supplier}',                 [SupplierController::class, 'update']);
    Route::get ('suppliers/{supplier}/statement',       [SupplierController::class, 'statement']);
    Route::post('suppliers/{supplier}/payment',         [SupplierController::class, 'recordPayment']);

    // Sales / POS
    Route::get ('sales/today-stats',    [SaleController::class, 'todayStats']);
    Route::get ('sales',                [SaleController::class, 'index']);
    Route::post('sales',                [SaleController::class, 'store']);
    Route::get ('sales/{sale}',         [SaleController::class, 'show']);
    Route::post('sales/{sale}/cancel',  [SaleController::class, 'cancel']);

    // Purchases
    Route::get ('purchases/today-stats', [PurchaseController::class, 'todayStats']);
    Route::get ('purchases',             [PurchaseController::class, 'index']);
    Route::post('purchases',             [PurchaseController::class, 'store']);
    Route::get ('purchases/{purchase}',  [PurchaseController::class, 'show']);

    // Expenses
    Route::get ('expense-categories',                      [ExpenseController::class, 'categories']);
    Route::post('expense-categories',                      [ExpenseController::class, 'storeCategory']);
    Route::put ('expense-categories/{expenseCategory}',    [ExpenseController::class, 'updateCategory']);
    Route::get ('expenses/today-stats',                    [ExpenseController::class, 'todayStats']);
    Route::get ('expenses',                                [ExpenseController::class, 'index']);
    Route::post('expenses',                                [ExpenseController::class, 'store']);
    Route::get ('expenses/{expense}',                      [ExpenseController::class, 'show']);
    Route::put ('expenses/{expense}',                      [ExpenseController::class, 'update']);
    Route::delete('expenses/{expense}',                    [ExpenseController::class, 'destroy']);

    // Returns
    Route::get ('returns',         [ReturnController::class, 'index']);
    Route::post('returns',         [ReturnController::class, 'store']);
    Route::get ('returns/{return}', [ReturnController::class, 'show']);

    // Payments
    Route::get('payments',               [PaymentController::class, 'index']);
    Route::get('payments/reconciliation',[PaymentController::class, 'reconciliation']);

    // Reports
    Route::get('reports/dashboard',            [ReportController::class, 'dashboard']);
    Route::get('reports/sales-chart',          [ReportController::class, 'salesChart']);
    Route::get('reports/sales',                [ReportController::class, 'sales']);
    Route::get('reports/top-products',         [ReportController::class, 'topProducts']);
    Route::get('reports/inventory',            [ReportController::class, 'inventory']);
    Route::get('reports/profit-loss',          [ReportController::class, 'profitLoss']);
    Route::get('reports/expenses',             [ReportController::class, 'expenses']);
    Route::get('reports/customer-balances',    [ReportController::class, 'customerBalances']);
    Route::get('reports/supplier-balances',    [ReportController::class, 'supplierBalances']);
    Route::get('reports/employee-performance', [ReportController::class, 'employeePerformance']);
    Route::get('reports/stock-movements',      [ReportController::class, 'stockMovements']);
    Route::get('reports/purchases',            [ReportController::class, 'purchases']);

    // Settings — admin only
    Route::middleware('role:administrator')->group(function () {
        Route::get ('settings',       [SettingController::class, 'index']);
        Route::post('settings',       [SettingController::class, 'update']);
        Route::get ('settings/{key}', [SettingController::class, 'get']);
    });
});
