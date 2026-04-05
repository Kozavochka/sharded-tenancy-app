@extends('layouts.app')

@section('content')
    <div class="panel">
        <h1>Tenant Admin</h1>
        <p class="muted">Create, delete and open tenant product dashboards.</p>
    </div>

    <div class="panel">
        <h2>Create Tenant</h2>

        <form method="POST" action="{{ route('admin.tenants.store') }}">
            @csrf

            <div class="grid">
                <div>
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required>
                </div>

                <div>
                    <label for="plan">Plan</label>
                    <select id="plan" name="plan" required>
                        <option value="free" @selected(old('plan') === 'free')>free</option>
                        <option value="enterprise" @selected(old('plan') === 'enterprise')>enterprise</option>
                    </select>
                </div>

                <div>
                    <label for="tenant_size">Tenant Size</label>
                    <select id="tenant_size" name="tenant_size" required>
                        <option value="small" @selected(old('tenant_size') === 'small')>small</option>
                        <option value="large" @selected(old('tenant_size') === 'large')>large</option>
                    </select>
                </div>

                <div>
                    <label for="domain">Domain</label>
                    <input id="domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="small.localhost">
                </div>
            </div>

            <div style="margin-top: 12px;">
                <button type="submit" class="btn">Create Tenant</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Tenants</h2>

        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Plan</th>
                <th>Size</th>
                <th>Shard</th>
                <th>Connection</th>
                <th>Schema</th>
                <th>Domain</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($tenants as $tenant)
                @php($domain = optional($tenant->domains->first())->domain)
                <tr>
                    <td>{{ $tenant->name }}</td>
                    <td>{{ $tenant->plan }}</td>
                    <td>{{ $tenant->tenant_size }}</td>
                    <td>{{ $tenant->shard }}</td>
                    <td>{{ $tenant->tenancy_db_connection }}</td>
                    <td>{{ $tenant->tenant_schema }}</td>
                    <td>{{ $domain ?: '-' }}</td>
                    <td>
                        <div class="actions">
                            @if($domain)
                                <a class="btn" href="{{ route('admin.tenants.open', $tenant) }}">Open</a>
                            @endif

                            <form class="inline" method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-danger" type="submit" onclick="return confirm('Delete tenant and schema?')">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="muted">No tenants yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
