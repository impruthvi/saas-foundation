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
     * Tables the guard watches, beyond those discovered from the models.
     *
     * @var list<string>
     */
    private static array $registered = [];

    /**
     * @var list<string>|null
     */
    private static ?array $discovered = null;

    private static bool $allowingUnscoped = false;

    /**
     * Watch a table that no application model declares, such as a test fixture.
     */
    public static function register(string $table): void
    {
        if (! in_array($table, self::$registered, true)) {
            self::$registered[] = $table;
        }
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
     * @return list<string>
     */
    public static function tables(): array
    {
        self::$discovered ??= self::discoverFromModels();

        return [...self::$discovered, ...self::$registered];
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

        if (str_contains($normalized, 'organization_id')) {
            return;
        }

        $touched = collect(self::tables())->first(
            fn (string $table): bool => preg_match('/\b(from|join|update|into)\s+'.preg_quote($table, '/').'\b/', $normalized) === 1,
        );

        throw_if(
            $touched !== null,
            RuntimeException::class,
            "Unscoped query against tenant-owned table [{$touched}]: {$sql}. "
            .'Resolve an organization first, or wrap the read in TenantQueryGuard::allowUnscoped().',
        );
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
