<?php

declare(strict_types=1);

use App\Http\Controllers\TenantProductController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    // Tenant identification strategy for HTTP requests: domain resolver.
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is ' . tenant('id');
    });

    Route::get('/products', [TenantProductController::class, 'index'])->name('tenant.products.index');
    Route::get('/products/create', [TenantProductController::class, 'create'])->name('tenant.products.create');
    Route::post('/products', [TenantProductController::class, 'store'])->name('tenant.products.store');
    Route::get('/products/{product}/edit', [TenantProductController::class, 'edit'])->name('tenant.products.edit');
    Route::put('/products/{product}', [TenantProductController::class, 'update'])->name('tenant.products.update');
    Route::patch('/products/{product}', [TenantProductController::class, 'update']);
    Route::delete('/products/{product}', [TenantProductController::class, 'destroy'])->name('tenant.products.destroy');
});
