<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TenantProductController extends Controller
{
    public function index(): View
    {
        return view('tenant.products.index', [
            'products' => Product::query()->orderByDesc('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('tenant.products.index', [
            'products' => Product::query()->orderByDesc('id')->get(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Product::query()->create($data);

        return redirect()
            ->route('tenant.products.index')
            ->with('status', 'Product created successfully.');
    }

    public function edit(Product $product): View
    {
        return view('tenant.products.edit', [
            'product' => $product,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()
            ->route('tenant.products.index')
            ->with('status', 'Product updated successfully.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()
            ->route('tenant.products.index')
            ->with('status', 'Product deleted successfully.');
    }
}
