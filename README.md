# Sharded Multi-Tenant Laravel App (PostgreSQL, Schema-per-Tenant)

This project is a Laravel 13 application with multi-tenancy based on `stancl/tenancy` and PostgreSQL.

It uses:

- one **central database** for tenant metadata (`tenants`, `domains`);
- two **tenant shard connections** (`tenant_shard_1`, `tenant_shard_2`);
- **schema-per-tenant** inside each shard database;
- Redis-backed domain resolver cache (`tenant_domain:{host}`);
- PostgreSQL advisory locks for provisioning and shard migrations;
- custom tenant bootstrap (`ShardSchemaBootstrapper`) that sets shard connection + `search_path`.

No database-per-tenant is used.

## Architecture

### Central DB

Central database stores:

- `tenants`
- `domains`
- extra tenant placement fields:
  - `name`
  - `plan`
  - `tenant_size`
  - `shard`
  - `tenancy_db_connection`
  - `tenant_schema`

### Tenant Data Layout

- Shard 1: one shared PostgreSQL database.
- Shard 2: one shared PostgreSQL database.
- Each tenant gets its own schema, for example: `tenant_a1b2c3d4`.
- Tenant tables (for demo: `products`) live in tenant schema, not in `public`.

### Tenant Identification Strategy (HTTP)

This project explicitly uses **domain-based** identification:

- `App\Http\Middleware\InitializeTenancyByCachedDomain`
- `Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains`

Defined in `routes/tenant.php`.

Domain resolution flow:

1. normalize host using `App\Services\TenantHostNormalizer`;
2. resolve tenant through `App\Services\CachedTenantResolver` cache;
3. on cache miss: query central DB by domain and warm cache;
4. initialize tenancy (`tenancy()->initialize($tenant)`), then bootstrap shard/schema.

### Custom Tenancy Bootstrap

Instead of `DatabaseTenancyBootstrapper`, project uses:

- `App\Tenancy\Bootstrappers\ShardSchemaBootstrapper`

On tenancy initialization:

1. Read `tenancy_db_connection` and `tenant_schema` from current tenant.
2. Compare target shard with current runtime shard source.
3. If shard changed: rebuild runtime `tenant` connection, `DB::purge('tenant')`, reconnect.
4. If shard is the same: reuse existing runtime `tenant` connection (skip rebuild/reconnect).
5. Set default to `tenant` and execute `SET search_path TO <tenant_schema>, public`.

On tenancy end:

1. Purge and remove runtime `tenant` connection.
2. Revert default connection to central (`pgsql`).
3. Reconnect central.

This keeps controller/model code clean: no manual shard/schema switching inside actions.

## Placement Rules

`App\Services\TenantPlacementService` is the single placement decision point:

- `plan=free` or `tenant_size=small` -> `shard_1`
- `plan=enterprise` or `tenant_size=large` -> `shard_2`
- fallback -> `config('shards.default')`

## Schema Lifecycle

`App\Services\TenantSchemaManager` handles all schema operations:

- safe schema name generation (`tenant_` + suffix);
- validation of schema format;
- create schema;
- drop schema;
- check schema exists;
- set `search_path`.

## Project Structure (Relevant Parts)

```text
app/
  Actions/
    CreateTenantAction.php
  Console/
    Commands/
      TenantsMigrateShard.php
  Http/
    Controllers/
      TenantProductController.php
  Models/
    Product.php
    Tenant.php
  Providers/
    TenancyServiceProvider.php
  Services/
    CachedTenantResolver.php
    TenantHostNormalizer.php
    TenantPlacementService.php
    TenantSchemaManager.php
  Tenancy/
    Bootstrappers/
      ShardSchemaBootstrapper.php

config/
  database.php
  shards.php
  tenancy.php

database/
  migrations/
    ... central migrations ...
    ... add_custom_columns_to_tenants_table ...
    tenant/
      ... create_products_table ...

routes/
  tenant.php
  console.php
```

## Prerequisites

- PHP 8.4+
- Composer
- PostgreSQL PHP extension:
  - `pdo_pgsql`
  - `pgsql`
- Access to Kubernetes services with port-forward (or equivalent DB access)

## Local DB Access (WSL / non-Kubernetes runtime)

You have two options:

1. Use your own local PostgreSQL databases (central + two shard databases).
2. Use Kubernetes databases via port-forward (commands below).

Run port-forward in separate terminals:

```bash
kubectl -n app-tenant port-forward svc/postgres-central-rw 15432:5432
kubectl -n app-tenant port-forward svc/postgres-shard-a-rw 25432:5432
kubectl -n app-tenant port-forward svc/postgres-shard-b-rw 35432:5432
```

Then local app uses:

- central: `127.0.0.1:15432`
- shard-1: `127.0.0.1:25432`
- shard-2: `127.0.0.1:35432`

For Kubernetes database manifests and setup details, see:

- https://github.com/Kozavochka/sharding-db

## Required Environment Variables

Use at least:

```dotenv
APP_NAME=ShardedTenancyApp
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=15432
DB_DATABASE=central_app
DB_USERNAME=central_app
DB_PASSWORD=CHANGE_ME_CENTRAL_APP_PASSWORD

TENANT_SHARD_1_DB_HOST=127.0.0.1
TENANT_SHARD_1_DB_PORT=25432
TENANT_SHARD_1_DB_DATABASE=postgres
TENANT_SHARD_1_DB_USERNAME=shard_a_admin
TENANT_SHARD_1_DB_PASSWORD=CHANGE_ME_SHARD_A_ADMIN_PASSWORD

TENANT_SHARD_2_DB_HOST=127.0.0.1
TENANT_SHARD_2_DB_PORT=35432
TENANT_SHARD_2_DB_DATABASE=postgres
TENANT_SHARD_2_DB_USERNAME=shard_b_admin
TENANT_SHARD_2_DB_PASSWORD=CHANGE_ME_SHARD_B_ADMIN_PASSWORD

CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=CHANGE_ME_REDIS_PASSWORD

TENANCY_DOMAIN_CACHE_STORE=redis
TENANCY_DOMAIN_CACHE_TTL_SECONDS=900
TENANCY_DOMAIN_CACHE_PREFIX=tenant_domain
```

## Setup

1. Install dependencies:

```bash
composer install
```

2. Prepare env:

```bash
cp .env.example .env
php artisan key:generate
```

3. Run central migrations:

```bash
php artisan migrate
```

4. Verify connections:

```bash
php artisan db:show --database=pgsql
php artisan db:show --database=tenant_shard_1
php artisan db:show --database=tenant_shard_2
```

## Create Tenant

Use custom provisioning command:

```bash
php artisan tenants:create "Small Co" free small small.localhost
php artisan tenants:create "Big Co" enterprise large big.localhost
```

This command:

1. decides shard using `TenantPlacementService`;
2. generates tenant schema via `TenantSchemaManager`;
3. creates tenant in central DB;
4. creates schema in selected shard DB;
5. creates domain record (if provided);
6. rolls back tenant/schema on failure.

Provisioning is protected by advisory locks:

- `tenant:provision:domain:{normalized_domain}` (or `tenant:provision:name:{name}` when domain is missing);
- `tenant:schema:{connection}:{schema}` for schema creation section.

## Tenant Migrations

Tenant migrations are stored only in:

- `database/migrations/tenant`

Run migrations by shard:

```bash
php artisan tenants:migrate-shard shard_1 --force
php artisan tenants:migrate-shard shard_2 --force
```

`tenants:migrate-shard` behavior:

- loads tenants by `tenants.shard`;
- initializes tenancy per tenant;
- runs `migrate --database=tenant --path=database/migrations/tenant`;
- ends tenancy and moves to next tenant.

Command execution is protected by shard-level advisory lock:

- `tenant:migrate-shard:{shard}`

## Products Demo API (Tenant Routes)

Tenant routes (domain-initialized tenancy):

- `GET /products` -> list products
- `POST /products` -> create product (`name`, `price`)

Routes are in `routes/tenant.php`, controller:

- `App\Http\Controllers\TenantProductController`

Resolver cache invalidation is wired to tenancy model events via:

- `App\Listeners\InvalidateCachedTenantResolver`
- events: `TenantSaved`, `TenantDeleted`, `DomainSaved`, `DomainDeleted`

## Why Data Is Isolated

Isolation is provided by:

1. selecting proper shard connection;
2. setting per-tenant `search_path` to tenant schema.

So regular Eloquent/Schema calls work naturally in tenant context, but data remains separated.

## Smoke Flow

```bash
# 1) central migrations
php artisan migrate

# 2) create tenants in different shards
php artisan tenants:create "Small Co" free small small.localhost
php artisan tenants:create "Big Co" enterprise large big.localhost

# 3) run tenant migrations per shard
php artisan tenants:migrate-shard shard_1 --force
php artisan tenants:migrate-shard shard_2 --force

# 4) test products in tenant context (via domain-based tenant routes)
```

## Tests

Run all tests:

```bash
php artisan test
```

Important test coverage includes:

- `TenantPlacementServiceTest` (placement rules);
- `TenantSchemaManagerTest` (schema safety/lifecycle helpers);
- `ShardedTenancyIntegrationTest` (end-to-end shard/schema/search_path/data isolation).
- `TenantRedisQueueIsolationTest` (Redis queue job isolation between tenants).

Note: integration test requires running PostgreSQL connections/port-forward and may skip if DB is not reachable.

## Troubleshooting

### `could not find driver (pgsql)`

Install extension, for example on Ubuntu/WSL:

```bash
sudo apt install -y php8.4-pgsql
php -m | rg -i "pgsql|pdo_pgsql"
```

### `PHP extension pdo_sqlite is required` (tests)

Install extension, for example on Ubuntu/WSL:

```bash
sudo apt install -y php8.4-sqlite3
php -m | rg -i "sqlite|pdo_sqlite"
```

### `password authentication failed`

Check `.env` credentials for central and shard connections.

### `permission denied for database ... create schema`

Grant `CREATE` on shard database to shard admin role, or use credentials with required privileges.

### `connection refused`

Port-forward is not active or wrong port is used.

## Current Commands Summary

- `php artisan tenants:create {name} {plan} {tenant_size} {domain?}`
- `php artisan tenants:migrate-shard {shard} --force`
- `php artisan tenants:list`
- `php artisan tenants:migrate`
