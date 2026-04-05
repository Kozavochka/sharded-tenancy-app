@extends('layouts.app')

@section('content')
    <div class="panel">
        <h1>Tenant Products</h1>
        <p class="muted">Tenant ID: {{ tenant('id') }}</p>
    </div>

    <div class="panel">
        <h2>Create Product</h2>
        <form method="POST" action="{{ route('tenant.products.store') }}">
            @csrf
            <div class="grid">
                <div>
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required>
                </div>
                <div>
                    <label for="price">Price</label>
                    <input id="price" name="price" type="number" step="0.01" min="0" value="{{ old('price') }}" required>
                </div>
            </div>
            <div style="margin-top: 12px;">
                <button type="submit" class="btn">Create Product</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Products</h2>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Price</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($products as $product)
                <tr>
                    <td>{{ $product->id }}</td>
                    <td>{{ $product->name }}</td>
                    <td>{{ number_format((float) $product->price, 2, '.', '') }}</td>
                    <td>
                        <div class="actions">
                            <a class="btn" href="{{ route('tenant.products.edit', $product) }}">Edit</a>
                            <form class="inline" method="POST" action="{{ route('tenant.products.destroy', $product) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-danger" type="submit" onclick="return confirm('Delete product?')">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted">No products yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
