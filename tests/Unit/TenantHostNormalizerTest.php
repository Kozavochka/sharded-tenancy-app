<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantHostNormalizer;
use InvalidArgumentException;
use Tests\TestCase;

class TenantHostNormalizerTest extends TestCase
{
    public function test_normalizes_host_with_port_case_and_trailing_dot(): void
    {
        $normalized = app(TenantHostNormalizer::class)->normalize('TeSt.Example.COM:8080.');

        $this->assertSame('test.example.com', $normalized);
    }

    public function test_rejects_empty_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TenantHostNormalizer::class)->normalize('  ');
    }

    public function test_rejects_host_with_unsupported_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TenantHostNormalizer::class)->normalize('exa mple.com');
    }
}

