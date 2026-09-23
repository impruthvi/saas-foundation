<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\TenantOwned;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Fails when SQL reads or writes a tenant-owned table without organization scope.
 *
 * Deliberate cross-tenant reads must use the explicit `allowUnscoped()` path.
 */
final class TenantQueryGuard
{
    /**
     * Tables the guard watches, beyond those discovered from the models,
     * each mapped to the columns that narrow a statement to one tenant.
     *
     * @var array<string, list<string>>
     */
    private static array $registered = [];

    /**
     * @var list<string>|null
     */
    private static ?array $discovered = null;

    private static bool $allowingUnscoped = false;

    /**
     * Watch a table that no application model declares.
     *
     * `$scopedBy` names the columns a statement must narrow on. It exists for
     * the entitlement package, whose tables carry `owner_id` rather than
     * `organization_id`, and whose hot path reads them by a primary key hashed
     * from the owner. Naming `id` as a scope column is therefore not a way of
     * accepting everything: it still refuses a statement that filters on any
     * other column, which is what a cross-owner sweep looks like. What it
     * cannot do is prove a given hash belongs to the resolved organization,
     * which is why `EntitlementBoundaryTest` asserts that part directly.
     *
     * @param  list<string>  $scopedBy
     */
    public static function register(string $table, array $scopedBy = ['organization_id']): void
    {
        self::$registered[$table] = $scopedBy;
    }

    /**
     * Install the listener for the current test.
     */
    public static function install(): void
    {
        self::$allowingUnscoped = false;

        DB::listen(function ($query): void {
            self::assertScoped($query->sql);
        });
    }

    /**
     * Reset per-test state. Registered fixtures do not survive a test.
     */
    public static function flush(): void
    {
        self::$registered = [];
        self::$allowingUnscoped = false;
    }

    /**
     * Read across tenants deliberately, the way the application's audited paths do.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function allowUnscoped(Closure $callback): mixed
    {
        $previous = self::$allowingUnscoped;

        self::$allowingUnscoped = true;

        try {
            return $callback();
        } finally {
            self::$allowingUnscoped = $previous;
        }
    }

    /**
     * The tables the guard watches: every `TenantOwned` model, plus registrations.
     *
     * @return array<string, list<string>>
     */
    public static function tables(): array
    {
        self::$discovered ??= self::discoverFromModels();

        return [
            ...array_fill_keys(self::$discovered, ['organization_id']),
            ...self::$registered,
        ];
    }

    private static function assertScoped(string $sql): void
    {
        if (self::$allowingUnscoped) {
            return;
        }

        $normalized = mb_strtolower(str_replace(['"', '`', '[', ']'], '', $sql));

        if (! Str::startsWith($normalized, ['select', 'update', 'delete'])) {
            return;
        }

        // An organization named anywhere in the statement scopes the whole of
        // it, including through a subquery. The narrower per-column check below
        // is reserved for tables that carry a different tenant key.
        if (str_contains($normalized, 'organization_id')) {
            return;
        }

        foreach (self::tables() as $table => $scopedBy) {
            if (preg_match('/\b(from|join|update|into)\s+'.preg_quote($table, '/').'\b/', $normalized) !== 1) {
                continue;
            }

            if (self::narrowedBy($normalized, $scopedBy)) {
                continue;
            }

            throw new RuntimeException(
                "Unscoped query against tenant-owned table [{$table}]: {$sql}. "
                .'Resolve an organization first, or wrap the read in TenantQueryGuard::allowUnscoped().',
            );
        }
    }

    /**
     * Whether the statement compares one of the tenant keys against something.
     *
     * Matching the comparison rather than the bare word keeps `id` from being
     * satisfied by a select list, and keeps `\bid\b` from matching `owner_id`.
     *
     * @param  list<string>  $scopedBy
     */
    private static function narrowedBy(string $normalized, array $scopedBy): bool
    {
        foreach ($scopedBy as $column) {
            if (preg_match('/\b'.preg_quote($column, '/').'\s*(=|<|>|in\b|is\b)/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function discoverFromModels(): array
    {
        $tables = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            /** @var SplFileInfo $file */
            $class = 'App\\Models\\'.Str::of($file->getRelativePathname())
                ->replace(['/', '.php'], ['\\', ''])
                ->value();

            if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, TenantOwned::class)) {
                continue;
            }

            /** @var Model $model */
            $model = new $class();

            $tables[] = $model->getTable();
        }

        return $tables;
    }
}
