<?php

declare(strict_types=1);

namespace App\Tenancy\Bootstrappers;

use App\Services\TenantSchemaManager;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

class ShardSchemaBootstrapper implements TenancyBootstrapper
{
    protected string $tenantConnectionName = 'tenant';
    protected string $tenantShardSourceConfigKey = '__shard_source';

    protected ?string $previousDefaultConnection = null;
    protected mixed $previousTenantConnectionConfig = null;

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
        $tenantId = (string) data_get($tenant, 'id', 'unknown');

        if ($shardConnection === '' || $tenantSchema === '') {
            throw new InvalidArgumentException('Tenant is missing tenancy_db_connection or tenant_schema.');
        }

        $baseConnectionConfig = config("database.connections.{$shardConnection}");

        if (! is_array($baseConnectionConfig)) {
            throw new InvalidArgumentException("Shard connection [{$shardConnection}] is not configured.");
        }

        $this->schemaManager->assertValidSchemaName($tenantSchema);

        // Preserve original state once per tenancy lifecycle.
        if ($this->previousDefaultConnection === null) {
            $this->previousDefaultConnection = $this->database->getDefaultConnection();
        }

        if ($this->previousTenantConnectionConfig === null) {
            $this->previousTenantConnectionConfig = $this->config->get("database.connections.{$this->tenantConnectionName}");
        }

        $currentShardSource = $this->currentShardSource();
        $reuseTenantConnection = is_string($currentShardSource) && $currentShardSource === $shardConnection;

        if (! $reuseTenantConnection) {
            // Build runtime tenant connection from selected shard connection.
            $runtimeConnectionConfig = $baseConnectionConfig;
            $runtimeConnectionConfig[$this->tenantShardSourceConfigKey] = $shardConnection;
            $this->config->set("database.connections.{$this->tenantConnectionName}", $runtimeConnectionConfig);
            $this->database->purge($this->tenantConnectionName);
            $this->database->reconnect($this->tenantConnectionName);
        }

        $this->config->set('database.default', $this->tenantConnectionName);
        $this->database->setDefaultConnection($this->tenantConnectionName);

        $this->schemaManager->setSearchPath($this->tenantConnectionName, $tenantSchema);

        Log::info('Tenancy bootstrap: switched to tenant shard/schema.', [
            'tenant_id' => $tenantId,
            'shard_connection' => $shardConnection,
            'tenant_schema' => $tenantSchema,
            'tenant_connection_reused' => $reuseTenantConnection,
        ]);

        if ((bool) config('app.debug')) {
            Log::debug('Tenancy bootstrap diagnostics.', [
                'tenant_id' => $tenantId,
                'default_connection' => $this->database->getDefaultConnection(),
                'search_path' => $this->schemaManager->currentSearchPath($this->tenantConnectionName),
            ]);
        }
    }

    public function revert()
    {
        $centralConnection = (string) config(
            'tenancy.database.central_connection',
            $this->previousDefaultConnection ?? config('database.default')
        );

        if ($centralConnection === '') {
            $centralConnection = $this->previousDefaultConnection ?: 'pgsql';
        }

        $this->database->purge($this->tenantConnectionName);
        if (is_array($this->previousTenantConnectionConfig)) {
            $this->config->set("database.connections.{$this->tenantConnectionName}", $this->previousTenantConnectionConfig);
        } else {
            $this->config->offsetUnset("database.connections.{$this->tenantConnectionName}");
        }

        $this->config->set('database.default', $centralConnection);
        $this->database->setDefaultConnection($centralConnection);
        $this->database->purge($centralConnection);
        $this->database->reconnect($centralConnection);

        Log::info('Tenancy revert: returned to central context.', [
            'central_connection' => $centralConnection,
        ]);

        if ((bool) config('app.debug')) {
            Log::debug('Tenancy revert diagnostics.', [
                'default_connection' => $this->database->getDefaultConnection(),
                'search_path' => $this->schemaManager->currentSearchPath($centralConnection),
            ]);
        }

        $this->previousTenantConnectionConfig = null;
        $this->previousDefaultConnection = null;
    }

    protected function currentShardSource(): ?string
    {
        $runtimeTenantConfig = $this->config->get("database.connections.{$this->tenantConnectionName}");

        if (! is_array($runtimeTenantConfig)) {
            return null;
        }

        $source = $runtimeTenantConfig[$this->tenantShardSourceConfigKey] ?? null;

        return is_string($source) && $source !== '' ? $source : null;
    }
}
