<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class TenantPlacementService
{
    /**
     * Decide where tenant should be placed and return shard metadata.
     *
     * @return array{shard: string, connection: string}
     */
    public function decide(string $plan, string $tenantSize): array
    {
        $normalizedPlan = strtolower(trim($plan));
        $normalizedTenantSize = strtolower(trim($tenantSize));

        $targetShard = $this->resolveShard($normalizedPlan, $normalizedTenantSize);
        $shardConfig = config("shards.shards.{$targetShard}");

        if (! is_array($shardConfig) || empty($shardConfig['connection'])) {
            throw new InvalidArgumentException("Shard [{$targetShard}] is not configured.");
        }

        return [
            'shard' => $targetShard,
            'connection' => (string) $shardConfig['connection'],
        ];
    }

    protected function resolveShard(string $plan, string $tenantSize): string
    {
        foreach ($this->rules() as $rule) {
            if ($rule['when']($plan, $tenantSize)) {
                return $rule['shard'];
            }
        }

        return (string) config('shards.default', 'shard_1');
    }

    /**
     * @return array<int, array{when: callable(string, string): bool, shard: string}>
     */
    protected function rules(): array
    {
        return [
            [
                'when' => fn (string $plan, string $tenantSize): bool => $plan === 'enterprise' || $tenantSize === 'large',
                'shard' => 'shard_2',
            ],
            [
                'when' => fn (string $plan, string $tenantSize): bool => $plan === 'free' || $tenantSize === 'small',
                'shard' => 'shard_1',
            ],
        ];
    }
}
