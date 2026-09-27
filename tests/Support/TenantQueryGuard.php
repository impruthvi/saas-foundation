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

final class TenantQueryGuard
{
    /**
     * Tables beyond those discovered from the models, each mapped to the columns that
     * narrow a statement to one tenant.
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
     * $scopedBy exists for the entitlement package, whose tables carry owner_id and are
     * read by a primary key hashed from the owner. Naming id still refuses a statement
     * filtering on any other column; proving a hash belongs to the resolved
     * organization is EntitlementBoundaryTest's job.
     *
     * @param  list<string>  $scopedBy
     */
    public static function register(string $table, array $scopedBy = ['organization_id']): void
    {
        self::$registered[$table] = $scopedBy;
    }

    public static function install(): void
    {
        self::$allowingUnscoped = false;

        DB::listen(function ($query): void {
            self::assertScoped($query->sql);
        });
    }

    public static function flush(): void
    {
        self::$registered = [];
        self::$allowingUnscoped = false;
    }

    /**
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

        // An organization named anywhere in the statement scopes all of it, including
        // through a subquery; the per-column check below is for tables with a different
        // tenant key.
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
     * Matching the comparison rather than the bare word keeps id from being satisfied
     * by a select list, and keeps \bid\b from matching owner_id.
     *
     * @param  list<string>  $scopedBy
     */
    private static function narrowedBy(string $normalized, array $scopedBy): bool
    {
        return array_any($scopedBy, fn (string $column): bool => preg_match('/\b'.preg_quote($column, '/').'\s*(=|<|>|in\b|is\b)/', $normalized) === 1);
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
