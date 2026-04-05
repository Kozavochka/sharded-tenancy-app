<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateTenantAction;
use App\Actions\DeleteTenantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(): View
    {
        $tenants = Tenant::query()
            ->with('domains')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.tenants.index', [
            'tenants' => $tenants,
        ]);
    }

    public function create(): View
    {
        return $this->index();
    }

    public function store(StoreTenantRequest $request, CreateTenantAction $createTenantAction): RedirectResponse
    {
        $data = $request->validated();

        $createTenantAction->execute(
            $data['name'],
            $data['plan'],
            $data['tenant_size'],
            $data['domain'] ?? null,
        );

        return redirect()
            ->route('admin.tenants.index')
            ->with('status', 'Tenant created successfully.');
    }

    public function destroy(Tenant $tenant, DeleteTenantAction $deleteTenantAction): RedirectResponse
    {
        $deleteTenantAction->execute($tenant);

        return redirect()
            ->route('admin.tenants.index')
            ->with('status', 'Tenant deleted successfully.');
    }

    public function open(Tenant $tenant): RedirectResponse
    {
        $tenant->loadMissing('domains');

        $domain = (string) optional($tenant->domains->first())->domain;

        abort_if($domain === '', 404, 'Tenant has no domain configured.');

        $scheme = request()->isSecure() ? 'https' : 'http';
        $port = request()->getPort();
        $portSuffix = in_array($port, [80, 443], true) ? '' : ':' . $port;

        return redirect()->away(sprintf('%s://%s%s/products', $scheme, $domain, $portSuffix));
    }
}
