<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseAdvisoryLock
{
    /**
     * Execute callback under PostgreSQL advisory lock.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function block(string $key, callable $callback, ?string $connection = null): mixed
    {
        $resolvedConnection = $this->resolveConnection($connection);
        [$key1, $key2] = $this->keyPair($key);

        DB::connection($resolvedConnection)->selectOne(
            'select pg_advisory_lock(?, ?)',
            [$key1, $key2]
        );

        try {
            return $callback();
        } finally {
            $released = DB::connection($resolvedConnection)->selectOne(
                'select pg_advisory_unlock(?, ?) as released',
                [$key1, $key2]
            );

            if (! (bool) ($released->released ?? false)) {
                Log::warning('Advisory lock was not released explicitly (likely released by connection reset).', [
                    'key' => $key,
                    'connection' => $resolvedConnection,
                ]);
            }
        }
    }

    protected function resolveConnection(?string $connection): string
    {
        if (is_string($connection) && $connection !== '') {
            return $connection;
        }

        return (string) config('tenancy.database.central_connection', config('database.default', 'pgsql'));
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function keyPair(string $key): array
    {
        $hash = sha1($key, true);

        return [
            $this->bytesToSignedInt32(substr($hash, 0, 4)),
            $this->bytesToSignedInt32(substr($hash, 4, 4)),
        ];
    }

    protected function bytesToSignedInt32(string $bytes): int
    {
        $value = unpack('N', $bytes)[1];

        if ($value >= 0x80000000) {
            return $value - 0x100000000;
        }

        return $value;
    }
}
