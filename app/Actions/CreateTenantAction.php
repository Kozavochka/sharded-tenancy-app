<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Tenant;
use App\Services\DatabaseAdvisoryLock;
use App\Services\TenantPlacementService;
use App\Services\TenantHostNormalizer;
use App\Services\TenantSchemaManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class CreateTenantAction
{
    public function __construct(
        protected TenantPlacementService $placementService,
        protected TenantSchemaManager $schemaManager,
        protected DatabaseAdvisoryLock $advisoryLock,
        protected TenantHostNormalizer $hostNormalizer,
    ) {
    }

    public function execute(
        string $name,
        string $plan,
        string $tenantSize,
        ?string $domain = null,
    ): Tenant {
        $provisionLockKey = $this->buildProvisioningLockKey($name, $domain);

        /** @var Tenant */
        return $this->advisoryLock->block($provisionLockKey, function () use ($name, $plan, $tenantSize, $domain): Tenant {
            $placement = $this->placementService->decide($plan, $tenantSize);
            $schema = $this->schemaManager->generateSchemaName();

            /** @var Tenant $tenant */
            $tenant = Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'plan' => $plan,
                'tenant_size' => $tenantSize,
                'shard' => $placement['shard'],
                'tenancy_db_connection' => $placement['connection'],
                'tenant_schema' => $schema,
            ]);

            $schemaCreated = false;

            try {
                $this->advisoryLock->block(
                    "tenant:schema:{$placement['connection']}:{$schema}",
                    function () use ($placement, $schema, &$schemaCreated): void {
                        $this->schemaManager->createSchema($placement['connection'], $schema);
                        $schemaCreated = true;
                    }
                );

                $this->runTenantMigrations($tenant);

                if ($domain !== null && trim($domain) !== '') {
                    $tenant->domains()->create([
                        'domain' => trim($domain),
                    ]);
                }

                return $tenant->fresh(['domains']) ?? $tenant;
            } catch (Throwable $e) {
                if ($schemaCreated) {
                    try {
                        $this->schemaManager->dropSchema($placement['connection'], $schema, true);
                    } catch (Throwable) {
                        // no-op: original exception is more important for caller
                    }
                }

                try {
                    $tenant->delete();
                } catch (Throwable) {
                    // no-op: original exception is more important for caller
                }

                throw $e;
            }
        });
    }

    protected function runTenantMigrations(Tenant $tenant): void
    {
        tenancy()->initialize($tenant);

        try {
            $exitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => [database_path('migrations/tenant')],
                '--realpath' => true,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException('Tenant migration command failed.');
            }
        } finally {
            tenancy()->end();
        }
    }

    protected function buildProvisioningLockKey(string $name, ?string $domain): string
    {
        $domain = is_string($domain) ? trim($domain) : '';

        if ($domain !== '') {
            try {
                $domain = $this->hostNormalizer->normalize($domain);
            } catch (InvalidArgumentException) {
                $domain = strtolower($domain);
            }

            return "tenant:provision:domain:{$domain}";
        }

        return 'tenant:provision:name:' . strtolower(trim($name));
    }
}
