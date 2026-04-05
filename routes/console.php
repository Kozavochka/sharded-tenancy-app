<?php

use App\Actions\CreateTenantAction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'tenants:create {name} {plan} {tenant_size} {domain?}',
    function (CreateTenantAction $createTenantAction): int {
        $name = (string) $this->argument('name');
        $plan = (string) $this->argument('plan');
        $tenantSize = (string) $this->argument('tenant_size');
        $domain = $this->argument('domain');

        try {
            $tenant = $createTenantAction->execute(
                $name,
                $plan,
                $tenantSize,
                is_string($domain) ? $domain : null,
            );
        } catch (\Throwable $e) {
            $this->error('Tenant provisioning failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->info('Tenant created successfully.');
        $this->line('id: ' . $tenant->id);
        $this->line('name: ' . (string) $tenant->name);
        $this->line('plan: ' . (string) $tenant->plan);
        $this->line('tenant_size: ' . (string) $tenant->tenant_size);
        $this->line('shard: ' . (string) $tenant->shard);
        $this->line('connection: ' . (string) $tenant->tenancy_db_connection);
        $this->line('schema: ' . (string) $tenant->tenant_schema);

        if ($tenant->relationLoaded('domains') && $tenant->domains->isNotEmpty()) {
            $this->line('domain: ' . (string) $tenant->domains->first()->domain);
        }

        return Command::SUCCESS;
    }
)->purpose('Create a tenant with shard placement and schema provisioning.');
