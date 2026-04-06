<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\DatabaseAdvisoryLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class TenantsMigrateShard extends Command
{
    protected $signature = 'tenants:migrate-shard
        {shard : Shard key from config/shards.php (e.g. shard_1)}
        {--force : Force the operation to run when in production}';

    protected $description = 'Run tenant migrations only for tenants in a specific shard.';

    public function __construct(
        protected DatabaseAdvisoryLock $advisoryLock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shard = (string) $this->argument('shard');
        $force = (bool) $this->option('force');

        $configuredShard = config("shards.shards.{$shard}");

        if (! is_array($configuredShard)) {
            $this->error("Unknown shard [{$shard}]. Check config/shards.php.");

            return self::FAILURE;
        }

        $lockKey = "tenant:migrate-shard:{$shard}";

        return (int) $this->advisoryLock->block($lockKey, function () use ($shard, $force): int {
            $tenants = Tenant::query()
                ->where('shard', $shard)
                ->orderBy('id')
                ->get();

            if ($tenants->isEmpty()) {
                $this->warn("No tenants found for shard [{$shard}].");

                return self::SUCCESS;
            }

            $this->info("Found {$tenants->count()} tenant(s) in shard [{$shard}].");

            foreach ($tenants as $tenant) {
                $this->newLine();
                $this->line("Tenant {$tenant->id} ({$tenant->tenant_schema})");

                try {
                    tenancy()->initialize($tenant);

                    $exitCode = Artisan::call('migrate', [
                        '--database' => 'tenant',
                        '--path' => [database_path('migrations/tenant')],
                        '--realpath' => true,
                        '--force' => $force,
                    ]);

                    $this->output->write(Artisan::output());

                    if ($exitCode !== 0) {
                        $this->error("Migration failed for tenant {$tenant->id}.");
                        tenancy()->end();

                        return self::FAILURE;
                    }
                } catch (Throwable $e) {
                    $this->error("Migration failed for tenant {$tenant->id}: {$e->getMessage()}");

                    try {
                        tenancy()->end();
                    } catch (Throwable) {
                        // no-op
                    }

                    return self::FAILURE;
                }

                tenancy()->end();
                $this->info("Tenant {$tenant->id} migrated.");
            }

            $this->newLine();
            $this->info("Shard [{$shard}] migration finished successfully.");

            return self::SUCCESS;
        });
    }
}
