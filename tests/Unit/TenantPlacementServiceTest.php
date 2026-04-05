<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantPlacementService;
use Tests\TestCase;

class TenantPlacementServiceTest extends TestCase
{
    public function test_places_free_plan_to_shard_1(): void
    {
        $result = app(TenantPlacementService::class)->decide('free', 'medium');

        $this->assertSame('shard_1', $result['shard']);
        $this->assertSame('tenant_shard_1', $result['connection']);
    }

    public function test_places_small_tenant_to_shard_1(): void
    {
        $result = app(TenantPlacementService::class)->decide('pro', 'small');

        $this->assertSame('shard_1', $result['shard']);
        $this->assertSame('tenant_shard_1', $result['connection']);
    }

    public function test_places_enterprise_plan_to_shard_2(): void
    {
        $result = app(TenantPlacementService::class)->decide('enterprise', 'small');

        $this->assertSame('shard_2', $result['shard']);
        $this->assertSame('tenant_shard_2', $result['connection']);
    }

    public function test_places_large_tenant_to_shard_2(): void
    {
        $result = app(TenantPlacementService::class)->decide('pro', 'large');

        $this->assertSame('shard_2', $result['shard']);
        $this->assertSame('tenant_shard_2', $result['connection']);
    }

    public function test_uses_default_shard_when_no_rule_matches(): void
    {
        $result = app(TenantPlacementService::class)->decide('pro', 'medium');

        $this->assertSame(config('shards.default'), $result['shard']);
        $this->assertSame('tenant_shard_1', $result['connection']);
    }
}
