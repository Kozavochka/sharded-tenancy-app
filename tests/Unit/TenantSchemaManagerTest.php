<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantSchemaManager;
use InvalidArgumentException;
use Tests\TestCase;

class TenantSchemaManagerTest extends TestCase
{
    public function test_generates_safe_schema_name_without_seed(): void
    {
        $schema = app(TenantSchemaManager::class)->generateSchemaName();

        $this->assertMatchesRegularExpression('/^tenant_[a-f0-9]{8}$/', $schema);
    }

    public function test_generates_deterministic_schema_name_with_seed(): void
    {
        $service = app(TenantSchemaManager::class);

        $first = $service->generateSchemaName('Tenant-A');
        $second = $service->generateSchemaName('Tenant-A');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^tenant_[a-f0-9]{8}$/', $first);
    }

    public function test_accepts_valid_schema_name(): void
    {
        app(TenantSchemaManager::class)->assertValidSchemaName('tenant_a1b2c3d4');

        $this->assertTrue(true);
    }

    public function test_rejects_schema_with_unsafe_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TenantSchemaManager::class)->assertValidSchemaName('tenant-ab12');
    }

    public function test_rejects_schema_without_tenant_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TenantSchemaManager::class)->assertValidSchemaName('ab12cd34');
    }

    public function test_rejects_schema_with_uppercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TenantSchemaManager::class)->assertValidSchemaName('tenant_Ab12cd34');
    }

    public function test_rejects_reserved_schema_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TenantSchemaManager::class)->assertValidSchemaName('public');
    }

    public function test_quotes_postgres_identifier_safely(): void
    {
        $quoted = app(TenantSchemaManager::class)->quoteIdentifier('tenant_a"b');

        $this->assertSame('"tenant_a""b"', $quoted);
    }
}
