<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission to create and manage projects, granted to both built-in roles.
 *
 * A plain member holds it as well as an administrator: projects are the work
 * the product exists for, and how many an organization may have is a question
 * for its plan rather than for its roles.
 *
 * Values are literal here for the same reason the catalog migration's are: this
 * file records what the database was given on this date, and must keep saying so
 * after the enums move on.
 */
return new class extends Migration
{
    private const string PERMISSION = 'organization.manage_projects';

    /**
     * @var list<string>
     */
    private const array ROLES = ['admin', 'member'];

    private const string GUARD = 'web';

    public function up(): void
    {
        $tables = config('permission.table_names');
        $team = config('permission.column_names.team_foreign_key');
        $now = now();

        DB::table($tables['permissions'])->insert([
            'name' => self::PERMISSION,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table($tables['permissions'])
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        $grants = DB::table($tables['roles'])
            ->whereIn('name', self::ROLES)
            ->where('guard_name', self::GUARD)
            ->whereNull($team)
            ->pluck('id')
            ->map(fn (int $roleId): array => [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ])
            ->all();

        DB::table($tables['role_has_permissions'])->insert($grants);

        $this->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = config('permission.table_names');

        $permissionId = DB::table($tables['permissions'])
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($permissionId !== null) {
            DB::table($tables['role_has_permissions'])->where('permission_id', $permissionId)->delete();
            DB::table($tables['model_has_permissions'])->where('permission_id', $permissionId)->delete();
            DB::table($tables['permissions'])->where('id', $permissionId)->delete();
        }

        $this->forgetCachedPermissions();
    }

    private function forgetCachedPermissions(): void
    {
        resolve(Factory::class)
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
