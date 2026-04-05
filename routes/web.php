<?php

use App\Http\Controllers\Admin\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.tenants.index');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
    Route::get('/tenants/{tenant}/open', [TenantController::class, 'open'])->name('tenants.open');
});
