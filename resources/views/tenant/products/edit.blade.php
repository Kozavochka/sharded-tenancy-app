@extends('layouts.app')

@section('content')
    <div class="panel">
        <h1>Edit Product</h1>
        <p class="muted">Tenant ID: {{ tenant('id') }}</p>
    </div>

    <div class="panel">
        <form method="POST" action="{{ route('tenant.products.update', $product) }}">
            @csrf
            @method('PUT')

            <div class="grid">
                <div>
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $product->name) }}" required>
                </div>
                <div>
                    <label for="price">Price</label>
                    <input id="price" name="price" type="number" min="0" step="0.01" value="{{ old('price', $product->price) }}" required>
                </div>
            </div>

            <div class="actions" style="margin-top: 12px;">
                <button type="submit" class="btn">Save</button>
                <a class="btn" href="{{ route('tenant.products.index') }}">Back</a>
            </div>
        </form>
    </div>
@endsection
