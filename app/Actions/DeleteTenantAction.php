<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Tenant;
use App\Services\TenantSchemaManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeleteTenantAction
{
    public function __construct(
        protected TenantSchemaManager $schemaManager,
    ) {
    }

    public function execute(Tenant $tenant): void
    {
        $connection = (string) $tenant->tenancy_db_connection;
        $schema = (string) $tenant->tenant_schema;

        if ($connection === '' || $schema === '') {
            throw new InvalidArgumentException('Tenant is missing tenancy_db_connection or tenant_schema.');
        }

        $this->schemaManager->assertValidSchemaName($schema);

        DB::connection(config('tenancy.database.central_connection', 'pgsql'))->transaction(function () use ($tenant, $connection, $schema): void {
            $tenant->domains()->delete();
            $tenant->delete();

            $this->schemaManager->dropSchema($connection, $schema, true);
        });
    }
}
