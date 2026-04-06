<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\CachedTenantResolver;
use Stancl\Tenancy\Events\Contracts\DomainEvent;
use Stancl\Tenancy\Events\Contracts\TenantEvent;

class InvalidateCachedTenantResolver
{
    public function __construct(
        protected CachedTenantResolver $cachedTenantResolver,
    ) {
    }

    public function handle(object $event): void
    {
        if ($event instanceof DomainEvent) {
            $this->cachedTenantResolver->invalidateHost((string) $event->domain->domain);
            $this->cachedTenantResolver->invalidateTenantDomains($event->domain->tenant);

            return;
        }

        if ($event instanceof TenantEvent) {
            $this->cachedTenantResolver->invalidateTenantDomains($event->tenant);
        }
    }
}

