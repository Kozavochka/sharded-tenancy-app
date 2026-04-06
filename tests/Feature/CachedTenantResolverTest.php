<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\CachedTenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class CachedTenantResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.domain_resolver.cache_store', 'array');
        config()->set('tenancy.domain_resolver.cache_ttl_seconds', 900);
        config()->set('tenancy.domain_resolver.cache_prefix', 'tenant_domain');
    }

    public function test_resolves_tenant_and_writes_cache(): void
    {
        $tenant = Tenant::create([
            'id' => 'tenant-1',
            'name' => 'Tenant One',
            'plan' => 'free',
            'tenant_size' => 'small',
            'shard' => 'shard_1',
            'tenancy_db_connection' => 'tenant_shard_1',
            'tenant_schema' => 'tenant_a1b2c3d4',
        ]);
        $tenant->domains()->create(['domain' => 'alpha.localhost']);

        $resolved = app(CachedTenantResolver::class)->resolveByHost('ALPHA.localhost:80');

        $this->assertInstanceOf(Tenant::class, $resolved);
        $this->assertSame($tenant->id, $resolved?->id);

        $cachedTenant = Cache::store('array')->get('tenant_domain:alpha.localhost');
        $this->assertInstanceOf(Tenant::class, $cachedTenant);
        $this->assertSame('tenant-1', $cachedTenant->id);
    }

    public function test_invalidates_all_cached_domains_for_tenant(): void
    {
        $tenant = Tenant::create([
            'id' => 'tenant-2',
            'name' => 'Tenant Two',
            'plan' => 'enterprise',
            'tenant_size' => 'large',
            'shard' => 'shard_2',
            'tenancy_db_connection' => 'tenant_shard_2',
            'tenant_schema' => 'tenant_d4c3b2a1',
        ]);
        $tenant->domains()->createMany([
            ['domain' => 'beta.localhost'],
            ['domain' => 'beta-alt.localhost'],
        ]);

        $resolver = app(CachedTenantResolver::class);
        $resolver->resolveByHost('beta.localhost');
        $resolver->resolveByHost('beta-alt.localhost');

        $this->assertNotNull(Cache::store('array')->get('tenant_domain:beta.localhost'));
        $this->assertNotNull(Cache::store('array')->get('tenant_domain:beta-alt.localhost'));

        $resolver->invalidateTenantDomains($tenant);

        $this->assertNull(Cache::store('array')->get('tenant_domain:beta.localhost'));
        $this->assertNull(Cache::store('array')->get('tenant_domain:beta-alt.localhost'));
    }
}
