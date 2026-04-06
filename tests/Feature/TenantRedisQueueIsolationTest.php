<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateTenantAction;
use App\Jobs\CreateTenantScopedProductJob;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\TenantSchemaManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TenantRedisQueueIsolationTest extends TestCase
{
    /** @var array<string, string> */
    protected array $dotenv = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql is required for integration tests.');
        }

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension is required for Redis queue integration test.');
        }

        $this->dotenv = $this->readDotenv(base_path('.env'));
        $this->configureConnectionsFromDotenv();

        if (! $this->isPortReachable($this->dotenv['DB_HOST'] ?? '127.0.0.1', (int) ($this->dotenv['DB_PORT'] ?? 15432))) {
            $this->markTestSkipped('Central PostgreSQL is not reachable. Start port-forward first.');
        }

        if (! $this->isPortReachable($this->dotenv['REDIS_HOST'] ?? '127.0.0.1', (int) ($this->dotenv['REDIS_PORT'] ?? 6379))) {
            $this->markTestSkipped('Redis is not reachable.');
        }

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.connection', 'default');
    }

    public function test_redis_queue_job_runs_in_the_same_tenant_context_it_was_dispatched_from(): void
    {
        $action = app(CreateTenantAction::class);
        $schemaManager = app(TenantSchemaManager::class);

        $small = null;
        $big = null;
        $queueName = 'tenant-test-' . uniqid();
        $smallProductName = 'queued-small-' . uniqid();
        $bigProductName = 'queued-big-' . uniqid();

        try {
            $small = $action->execute('Queue Small', 'free', 'small', 'queue-small-' . uniqid() . '.local');
            $big = $action->execute('Queue Big', 'enterprise', 'large', 'queue-big-' . uniqid() . '.local');

            $this->assertTrue($schemaManager->schemaExists($small->tenancy_db_connection, $small->tenant_schema));
            $this->assertTrue($schemaManager->schemaExists($big->tenancy_db_connection, $big->tenant_schema));

            tenancy()->initialize($small);
            dispatch((new CreateTenantScopedProductJob($smallProductName, 11.11))->onConnection('redis')->onQueue($queueName));
            tenancy()->end();

            tenancy()->initialize($big);
            dispatch((new CreateTenantScopedProductJob($bigProductName, 22.22))->onConnection('redis')->onQueue($queueName));
            tenancy()->end();

            $workerArgs = [
                'connection' => 'redis',
                '--queue' => $queueName,
                '--once' => true,
                '--sleep' => 0,
                '--tries' => 1,
            ];

            $this->assertSame(0, Artisan::call('queue:work', $workerArgs));
            $this->assertSame(0, Artisan::call('queue:work', $workerArgs));

            tenancy()->initialize($small);
            $this->assertSame(1, Product::query()->where('name', $smallProductName)->count());
            $this->assertSame(0, Product::query()->where('name', $bigProductName)->count());
            tenancy()->end();

            tenancy()->initialize($big);
            $this->assertSame(1, Product::query()->where('name', $bigProductName)->count());
            $this->assertSame(0, Product::query()->where('name', $smallProductName)->count());
            tenancy()->end();
        } finally {
            try {
                tenancy()->end();
            } catch (\Throwable) {
                // no-op
            }

            // Clean up potentially remaining test queue keys.
            try {
                Redis::connection('default')->del("queues:{$queueName}", "queues:{$queueName}:reserved", "queues:{$queueName}:notify");
            } catch (\Throwable) {
                // no-op
            }

            if ($small instanceof Tenant) {
                $this->cleanupTenant($small);
            }

            if ($big instanceof Tenant) {
                $this->cleanupTenant($big);
            }
        }
    }

    protected function cleanupTenant(Tenant $tenant): void
    {
        $schemaManager = app(TenantSchemaManager::class);

        if ($tenant->tenancy_db_connection && $tenant->tenant_schema) {
            if ($schemaManager->schemaExists($tenant->tenancy_db_connection, $tenant->tenant_schema)) {
                $schemaManager->dropSchema($tenant->tenancy_db_connection, $tenant->tenant_schema, true);
            }
        }

        $tenant->domains()->delete();
        $tenant->delete();
    }

    protected function configureConnectionsFromDotenv(): void
    {
        config()->set('tenancy.database.central_connection', 'pgsql');
        config()->set('database.default', 'pgsql');

        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => $this->dotenv['DB_HOST'] ?? '127.0.0.1',
            'port' => $this->dotenv['DB_PORT'] ?? '15432',
            'database' => $this->dotenv['DB_DATABASE'] ?? 'central_app',
            'username' => $this->dotenv['DB_USERNAME'] ?? 'postgres',
            'password' => $this->dotenv['DB_PASSWORD'] ?? '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        config()->set('database.connections.tenant_shard_1.host', $this->dotenv['TENANT_SHARD_1_DB_HOST'] ?? '127.0.0.1');
        config()->set('database.connections.tenant_shard_1.port', $this->dotenv['TENANT_SHARD_1_DB_PORT'] ?? '25432');
        config()->set('database.connections.tenant_shard_1.database', $this->dotenv['TENANT_SHARD_1_DB_DATABASE'] ?? 'postgres');
        config()->set('database.connections.tenant_shard_1.username', $this->dotenv['TENANT_SHARD_1_DB_USERNAME'] ?? 'postgres');
        config()->set('database.connections.tenant_shard_1.password', $this->dotenv['TENANT_SHARD_1_DB_PASSWORD'] ?? '');

        config()->set('database.connections.tenant_shard_2.host', $this->dotenv['TENANT_SHARD_2_DB_HOST'] ?? '127.0.0.1');
        config()->set('database.connections.tenant_shard_2.port', $this->dotenv['TENANT_SHARD_2_DB_PORT'] ?? '35432');
        config()->set('database.connections.tenant_shard_2.database', $this->dotenv['TENANT_SHARD_2_DB_DATABASE'] ?? 'postgres');
        config()->set('database.connections.tenant_shard_2.username', $this->dotenv['TENANT_SHARD_2_DB_USERNAME'] ?? 'postgres');
        config()->set('database.connections.tenant_shard_2.password', $this->dotenv['TENANT_SHARD_2_DB_PASSWORD'] ?? '');

        DB::purge('pgsql');
        DB::purge('tenant_shard_1');
        DB::purge('tenant_shard_2');
        DB::setDefaultConnection('pgsql');
    }

    /**
     * @return array<string, string>
     */
    protected function readDotenv(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $result = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $result[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $result;
    }

    protected function isPortReachable(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.5);

        if (! is_resource($connection)) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
