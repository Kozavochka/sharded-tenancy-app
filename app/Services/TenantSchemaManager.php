<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantSchemaManager
{
    /**
     * Generate a safe tenant schema name.
     */
    public function generateSchemaName(?string $seed = null): string
    {
        $suffix = $seed === null
            ? bin2hex(random_bytes(4))
            : substr(sha1(strtolower(trim($seed))), 0, 8);

        $schema = 'tenant_' . $suffix;

        $this->assertValidSchemaName($schema);

        return $schema;
    }

    public function createSchema(string $connection, string $schema): void
    {
        $this->assertValidSchemaName($schema);

        $quoted = $this->quoteIdentifier($schema);

        DB::connection($connection)->statement("create schema if not exists {$quoted}");
    }

    public function dropSchema(string $connection, string $schema, bool $cascade = false): void
    {
        $this->assertValidSchemaName($schema);

        $quoted = $this->quoteIdentifier($schema);
        $cascadeSql = $cascade ? ' cascade' : '';

        DB::connection($connection)->statement("drop schema if exists {$quoted}{$cascadeSql}");
    }

    public function schemaExists(string $connection, string $schema): bool
    {
        $this->assertValidSchemaName($schema);

        /** @var object{exists: bool|int|string}|null $row */
        $row = DB::connection($connection)->selectOne(
            'select exists(select 1 from information_schema.schemata where schema_name = ?) as exists',
            [$schema]
        );

        return (bool) ($row->exists ?? false);
    }

    public function setSearchPath(string $connection, string $schema): void
    {
        $this->assertValidSchemaName($schema);

        DB::connection($connection)->statement(
            "select set_config('search_path', ?, false)",
            [$this->buildSearchPath($schema)]
        );
    }

    public function currentSearchPath(string $connection): string
    {
        /** @var object{sp: string}|null $row */
        $row = DB::connection($connection)->selectOne("select current_setting('search_path') as sp");

        return (string) ($row->sp ?? '');
    }

    public function assertValidSchemaName(string $schema): void
    {
        $normalized = strtolower(trim($schema));

        if ($normalized !== $schema) {
            throw new InvalidArgumentException('Schema name must be lowercase and trimmed.');
        }

        if (! preg_match('/^tenant_[a-z0-9_]+$/', $schema)) {
            throw new InvalidArgumentException(
                'Schema name must match pattern: tenant_[a-z0-9_]+.'
            );
        }

        $reserved = [
            'public',
            'information_schema',
            'pg_catalog',
            'pg_toast',
        ];

        if (in_array($schema, $reserved, true)) {
            throw new InvalidArgumentException("Schema [{$schema}] is reserved.");
        }
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function buildSearchPath(string $schema): string
    {
        return $this->quoteIdentifier($schema) . ', public';
    }
}
