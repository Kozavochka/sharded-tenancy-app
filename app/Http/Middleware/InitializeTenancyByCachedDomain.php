<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CachedTenantResolver;
use Closure;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByCachedDomain
{
    /** @var callable|null */
    public static $onFail;

    public function __construct(
        protected Tenancy $tenancy,
        protected CachedTenantResolver $resolver,
    ) {
    }

    public function handle($request, Closure $next)
    {
        $host = (string) $request->getHost();

        try {
            $tenant = $this->resolver->resolveByHost($host);

            if ($tenant === null) {
                throw new TenantCouldNotBeIdentifiedOnDomainException($host);
            }

            $this->tenancy->initialize($tenant);
        } catch (TenantCouldNotBeIdentifiedException $e) {
            $onFail = static::$onFail ?? function ($e) {
                throw $e;
            };

            return $onFail($e, $request, $next);
        }

        return $next($request);
    }
}

