<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class TenantHostNormalizer
{
    public function normalize(string $host): string
    {
        $normalized = strtolower(trim($host));

        if ($normalized === '') {
            throw new InvalidArgumentException('Host cannot be empty.');
        }

        if (str_starts_with($normalized, '[') && str_contains($normalized, ']')) {
            $normalized = substr($normalized, 0, (int) strpos($normalized, ']') + 1);
        } else {
            $normalized = explode(':', $normalized, 2)[0];
        }

        $normalized = rtrim($normalized, '.');

        if ($normalized === '') {
            throw new InvalidArgumentException('Host cannot be empty after normalization.');
        }

        if (! preg_match('/^[a-z0-9.-]+$/', $normalized)) {
            throw new InvalidArgumentException('Host contains unsupported characters.');
        }

        return $normalized;
    }
}

