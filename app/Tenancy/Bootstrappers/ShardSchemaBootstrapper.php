<?php

declare(strict_types=1);

namespace App\Tenancy\Bootstrappers;

use App\Services\TenantSchemaManager;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class ShardSchemaBootstrapper implements TenancyBootstrapper
{
    protected string $tenantConnectionName = 'tenant';

    protected ?string $previousDefaultConnection = null;

    public function __construct(
        protected Repository $config,
        protected BaseDatabaseManager $database,
        protected TenantSchemaManager $schemaManager,
    ) {
    }

    public function bootstrap(Tenant $tenant)
    {
        $shardConnection = (string) data_get($tenant, 'tenancy_db_connection', '');
        $tenantSchema = (string) data_get($tenant, 'tenant_schema', '');

        if ($shardConnection === '' || $tenantSchema === '') {
            throw new InvalidArgumentException('Tenant is missing tenancy_db_connection or tenant_schema.');
        }

        $baseConnectionConfig = config("database.connections.{$shardConnection}");

        if (! is_array($baseConnectionConfig)) {
            throw new InvalidArgumentException("Shard connection [{$shardConnection}] is not configured.");
        }

        $this->schemaManager->assertValidSchemaName($tenantSchema);
        $this->previousDefaultConnection = $this->database->getDefaultConnection();

        // Build runtime tenant connection from selected shard connection.
        $this->config->set("database.connections.{$this->tenantConnectionName}", $baseConnectionConfig);
        $this->database->purge($this->tenantConnectionName);

        $this->config->set('database.default', $this->tenantConnectionName);
        $this->database->setDefaultConnection($this->tenantConnectionName);
        $this->database->reconnect($this->tenantConnectionName);

        $this->schemaManager->setSearchPath($this->tenantConnectionName, $tenantSchema);
    }

    public function revert()
    {
        $centralConnection = (string) config(
            'tenancy.database.central_connection',
            $this->previousDefaultConnection ?? config('database.default')
        );

        $this->database->purge($this->tenantConnectionName);
        $this->config->offsetUnset("database.connections.{$this->tenantConnectionName}");

        $this->config->set('database.default', $centralConnection);
        $this->database->setDefaultConnection($centralConnection);
        $this->database->purge($centralConnection);
        $this->database->reconnect($centralConnection);

        $this->previousDefaultConnection = null;
    }
}
