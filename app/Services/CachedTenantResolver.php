<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CachedTenantResolver
{
    public function __construct(
        protected CacheFactory $cacheFactory,
        protected TenantHostNormalizer $hostNormalizer,
    ) {
    }

    public function resolveByHost(string $host): ?Tenant
    {
        $normalizedHost = $this->hostNormalizer->normalize($host);
        $key = $this->cacheKey($normalizedHost);
        $cache = $this->cacheStore();

        $cached = $cache->get($key);
        if ($cached instanceof Tenant) {
            Log::debug('Tenant resolver cache hit.', [
                'host' => $normalizedHost,
                'tenant_id' => (string) $cached->id,
            ]);

            return $cached;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()
            ->whereHas('domains', function (Builder $query) use ($normalizedHost): void {
                $query->where('domain', $normalizedHost);
            })
            ->with('domains')
            ->first();

        if (! $tenant instanceof Tenant) {
            Log::debug('Tenant resolver cache miss (tenant not found).', [
                'host' => $normalizedHost,
            ]);

            return null;
        }

        $cache->put($key, $tenant, $this->cacheTtlSeconds());

        Log::debug('Tenant resolver cache miss (tenant cached).', [
            'host' => $normalizedHost,
            'tenant_id' => (string) $tenant->id,
            'ttl_seconds' => $this->cacheTtlSeconds(),
        ]);

        return $tenant;
    }

    public function invalidateHost(string $host): void
    {
        try {
            $normalizedHost = $this->hostNormalizer->normalize($host);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->cacheStore()->forget($this->cacheKey($normalizedHost));
    }

    public function invalidateTenantDomains(Tenant $tenant): void
    {
        $tenant->loadMissing('domains');

        foreach ($tenant->domains as $domain) {
            $this->invalidateHost((string) $domain->domain);
        }
    }

    protected function cacheStore(): CacheRepository
    {
        $store = config('tenancy.domain_resolver.cache_store');

        return $this->cacheFactory->store(is_string($store) && $store !== '' ? $store : null);
    }

    protected function cacheTtlSeconds(): int
    {
        $ttl = (int) config('tenancy.domain_resolver.cache_ttl_seconds', 900);

        return $ttl > 0 ? $ttl : 900;
    }

    protected function cacheKey(string $normalizedHost): string
    {
        $prefix = (string) config('tenancy.domain_resolver.cache_prefix', 'tenant_domain');

        return "{$prefix}:{$normalizedHost}";
    }
}
