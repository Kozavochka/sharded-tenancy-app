<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateTenantAction;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\TenantSchemaManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShardedTenancyIntegrationTest extends TestCase
{
    /** @var array<string, string> */
    protected array $dotenv = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql is required for integration tests.');
        }

        $this->dotenv = $this->readDotenv(base_path('.env'));
        $this->configureConnectionsFromDotenv();

        if (! $this->isPortReachable($this->dotenv['DB_HOST'] ?? '127.0.0.1', (int) ($this->dotenv['DB_PORT'] ?? 15432))) {
            $this->markTestSkipped('Central PostgreSQL is not reachable. Start port-forward first.');
        }
    }

    public function test_sharded_schema_tenancy_end_to_end(): void
    {
        $action = app(CreateTenantAction::class);
        $schemaManager = app(TenantSchemaManager::class);

        $small = null;
        $big = null;

        try {
            $small = $action->execute(
                'Test Small',
                'free',
                'small',
                'test-small-' . uniqid() . '.local'
            );

            $big = $action->execute(
                'Test Big',
                'enterprise',
                'large',
                'test-big-' . uniqid() . '.local'
            );

            $this->assertSame('shard_1', $small->shard);
            $this->assertSame('tenant_shard_1', $small->tenancy_db_connection);
            $this->assertNotEmpty($small->tenant_schema);

            $this->assertSame('shard_2', $big->shard);
            $this->assertSame('tenant_shard_2', $big->tenancy_db_connection);
            $this->assertNotEmpty($big->tenant_schema);

            $this->assertTrue($schemaManager->schemaExists($small->tenancy_db_connection, $small->tenant_schema));
            $this->assertTrue($schemaManager->schemaExists($big->tenancy_db_connection, $big->tenant_schema));

            $this->artisan('tenants:migrate-shard', ['shard' => 'shard_1', '--force' => true])->assertExitCode(0);
            $this->artisan('tenants:migrate-shard', ['shard' => 'shard_2', '--force' => true])->assertExitCode(0);

            tenancy()->initialize($small);
            $this->assertSame('tenant', config('database.default'));
            $searchPath = (string) DB::selectOne("select current_setting('search_path') as sp")->sp;
            $this->assertStringContainsString($small->tenant_schema, str_replace('"', '', $searchPath));
            $this->assertStringContainsString('public', $searchPath);

            Product::create(['name' => 'small-only', 'price' => 11.11]);
            $this->assertSame(1, Product::query()->where('name', 'small-only')->count());
            $this->assertSame(0, Product::query()->where('name', 'big-only')->count());
            tenancy()->end();

            tenancy()->initialize($big);
            Product::create(['name' => 'big-only', 'price' => 22.22]);
            $this->assertSame(1, Product::query()->where('name', 'big-only')->count());
            $this->assertSame(0, Product::query()->where('name', 'small-only')->count());
            tenancy()->end();

            $inShard1Public = DB::connection('tenant_shard_1')->selectOne(
                "select exists(select 1 from information_schema.tables where table_schema = 'public' and table_name = 'products') as e"
            )->e;
            $inShard2Public = DB::connection('tenant_shard_2')->selectOne(
                "select exists(select 1 from information_schema.tables where table_schema = 'public' and table_name = 'products') as e"
            )->e;
            $inCentralPublic = DB::connection('pgsql')->selectOne(
                "select exists(select 1 from information_schema.tables where table_schema = 'public' and table_name = 'products') as e"
            )->e;

            $this->assertFalse((bool) $inShard1Public);
            $this->assertFalse((bool) $inShard2Public);
            $this->assertFalse((bool) $inCentralPublic);
        } finally {
            try {
                tenancy()->end();
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
