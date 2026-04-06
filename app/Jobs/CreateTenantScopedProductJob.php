<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateTenantScopedProductJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $name,
        public float $price,
    ) {
    }

    public function handle(): void
    {
        Product::query()->create([
            'name' => $this->name,
            'price' => $this->price,
        ]);
    }
}

