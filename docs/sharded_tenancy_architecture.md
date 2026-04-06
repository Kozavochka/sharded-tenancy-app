# Sharded Tenancy Architecture in Laravel

## 1. Goal and Isolation Model

This project implements multi-tenancy with `stancl/tenancy` using the following model:

- a `central` database stores only tenant metadata and domains;
- tenant data is stored in PostgreSQL shard connections;
- each tenant is isolated by a dedicated PostgreSQL `schema` inside a shard;
- tenant context switching is implemented via shard connection selection + `search_path`.

Core model: **`schema-per-tenant inside shard`**, not `database-per-tenant`.

## 2. Implementation Components

### 2.1 Central metadata

Tenant model: `app/Models/Tenant.php`

Tenant custom columns:

- `shard`
- `tenancy_db_connection`
- `tenant_schema`
- (also: `name`, `plan`, `tenant_size`)

Central tables are migrated via regular `php artisan migrate`.

### 2.2 Database connections

Configuration: `config/database.php`

Connections in use:

- `pgsql` as central;
- `tenant_shard_1` as shard 1;
- `tenant_shard_2` as shard 2.

All shard connections keep base `search_path = public`; tenant schema is applied at runtime by the custom bootstrapper.

### 2.3 Tenant placement rules

A placement service decides shard and connection at tenant creation time:

- small/free -> `shard_1` / `tenant_shard_1`
- large/enterprise -> `shard_2` / `tenant_shard_2`

See `app/Services/TenantPlacementService.php`.

### 2.4 Schema management

Service: `app/Services/TenantSchemaManager.php`

Responsibilities:

- generate safe schema names (`tenant_<suffix>`);
- validate schema names;
- run `create schema` / `drop schema`;
- set `search_path` safely.

`search_path` is set via:

```sql
select set_config('search_path', ?, false)
```

with a value like `"tenant_xxx", public`.

### 2.5 Domain resolver cache

Services:

- `app/Services/TenantHostNormalizer.php`
- `app/Services/CachedTenantResolver.php`

Responsibilities:

- normalize request host before tenant lookup;
- resolve tenant by domain using cache key `tenant_domain:{normalized_host}`;
- on cache miss, load tenant from central DB and warm cache;
- invalidate cached keys when tenant/domain metadata changes.

Configuration is in `config/tenancy.php`:

- `domain_resolver.cache_store`
- `domain_resolver.cache_ttl_seconds`
- `domain_resolver.cache_prefix`

### 2.6 Advisory lock service

Service:

- `app/Services/DatabaseAdvisoryLock.php`

Responsibilities:

- acquire PostgreSQL advisory lock by string key;
- execute critical section callback;
- release lock in `finally`.

Used in:

- `CreateTenantAction` (provisioning lock + schema lock);
- `TenantsMigrateShard` (shard-level lock).

## 3. Tenancy Bootstrap: Context Switch Lifecycle

Custom bootstrapper:
`app/Tenancy/Bootstrappers/ShardSchemaBootstrapper.php`

Registered in `config/tenancy.php` under `bootstrappers`.

### 3.1 Bootstrap (enter tenant context)

On `tenancy()->initialize($tenant)`:

1. Reads from tenant record:
- `tenancy_db_connection`
- `tenant_schema`

2. Validates:
- shard connection exists in `database.connections`;
- schema name passes `assertValidSchemaName()`.

3. Builds runtime `tenant` connection from the selected shard connection.

4. Executes:
- `DB::purge('tenant')`
- `set database.default = tenant`
- `DB::reconnect('tenant')`

5. Calls `setSearchPath('tenant', tenant_schema)`.

6. Writes bootstrap diagnostics log (tenant id / shard connection / schema).

### 3.2 Revert (return to central context)

On `tenancy()->end()`:

1. Resolves central connection from `tenancy.database.central_connection`.
2. `purge('tenant')`.
3. Restores/clears runtime `database.connections.tenant` config.
4. Sets `database.default` back to central.
5. `purge + reconnect` central connection.
6. Writes revert log.

## 4. HTTP Tenant Request Pipeline

Tenant routes: `routes/tenant.php`

Middleware chain:

1. `InitializeTenancyByCachedDomain`
2. `PreventAccessFromCentralDomains`

Tenancy event provider: `app/Providers/TenancyServiceProvider.php`

- `TenancyInitialized` -> `BootstrapTenancy`
- `TenancyEnded` -> `RevertToCentralContext`
- `TenantSaved|TenantDeleted|DomainSaved|DomainDeleted` -> `InvalidateCachedTenantResolver`

Result: for a single HTTP request, tenant context and `search_path` are set once on initialize, then reset on end.

## 5. Jobs and Long-Lived Processes

### 5.1 Queue bootstrapper

`QueueTenancyBootstrapper` is enabled in `config/tenancy.php`.

### 5.2 Queue storage must stay central

Config: `config/queue.php`

For queue storage, project currently uses Redis driver:

- `QUEUE_CONNECTION=redis`;
- Redis connection is configured in `config/database.php`.

Tenant context for job execution is still managed by tenancy lifecycle + `QueueTenancyBootstrapper`.

### 5.3 Isolation between jobs

Due to tenancy lifecycle (`initialize` -> bootstrappers -> `end` -> revert) and `purge/reconnect`, `search_path` should not leak across jobs from different tenants.

## 6. Manual Initialization (CLI/Scripts)

Supported pattern:

```php
$tenant = Tenant::find($id);
tenancy()->initialize($tenant);
// tenant schema work
tenancy()->end();
```

Examples in this project:

- `app/Actions/CreateTenantAction.php`
- `app/Console/Commands/TenantsMigrateShard.php`

## 7. Security

### 7.1 Source of switching values

`tenancy_db_connection` and `tenant_schema` are read from the tenant record in central DB.

### 7.2 Schema validation

`TenantSchemaManager::assertValidSchemaName()` enforces:

- lowercase + trimmed string;
- pattern `^tenant_[a-z0-9_]+$`;
- reserved schemas are rejected (`public`, `information_schema`, `pg_catalog`, `pg_toast`).

### 7.3 Safe search_path setup

`search_path` is applied through a bound parameter (`set_config`), without direct interpolation of unsafe user input.

## 8. Observability

`ShardSchemaBootstrapper` logs:

- bootstrap `info` log:
- `tenant_id`
- `shard_connection`
- `tenant_schema`

- revert `info` log:
- `central_connection`

- `debug` logs (only when `APP_DEBUG=true`):
- current `database.default`
- current `search_path`

This provides context-switch diagnostics without noisy per-query logging.

`CachedTenantResolver` also writes debug logs for cache hit/miss and cache warmup.

## 9. Tenant Logic Map (Code)

- Bootstrap/revert: `app/Tenancy/Bootstrappers/ShardSchemaBootstrapper.php`
- HTTP tenant init middleware: `app/Http/Middleware/InitializeTenancyByCachedDomain.php`
- Resolver + host normalization: `app/Services/CachedTenantResolver.php`, `app/Services/TenantHostNormalizer.php`
- Resolver cache invalidation: `app/Listeners/InvalidateCachedTenantResolver.php`
- Advisory lock service: `app/Services/DatabaseAdvisoryLock.php`
- Schema lifecycle: `app/Services/TenantSchemaManager.php`
- Tenant creation flow: `app/Actions/CreateTenantAction.php`
- Per-shard tenant migrations: `app/Console/Commands/TenantsMigrateShard.php`
- Tenancy config: `config/tenancy.php`
- Queue config: `config/queue.php`

## 10. Constraints and Recommendations

1. Do not execute `SET search_path` in controllers/models/job `handle()`.
2. Do not switch tenant context via ad-hoc `config(['database.default' => ...])` outside the bootstrapper.
3. In all manual/CLI flows always use paired lifecycle:
- `tenancy()->initialize(...)`
- `try/finally`
- `tenancy()->end()`
4. Keep queue storage explicitly configured (`redis` connection), independent from temporary `database.default = tenant`.
5. When adding shards, extend `config/shards.php` and matching `database.connections` only.

## 11. Queue Isolation Validation

Feature test:

- `tests/Feature/TenantRedisQueueIsolationTest.php`

What it validates:

- jobs dispatched from different tenant contexts into Redis queue;
- jobs processed by worker keep correct tenant context;
- records created by job remain isolated in each tenant schema.

---

This document describes the current implementation and serves as a technical reference for maintaining and evolving the tenancy layer.
