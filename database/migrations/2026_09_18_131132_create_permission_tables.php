<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role definitions are global and assignments need an organization; team keys cascade
 * so a deleted organization leaves no grants. The catalog is seeded here with literal
 * values, so migrate alone works and later enum changes cannot rewrite history.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const array PERMISSIONS = [
        'organization.view_members' => 'See who is in this organization, and who has been asked',
        'organization.invite' => 'Invite people, resend and revoke invitations',
        'organization.manage_members' => "Change a member's rank, and remove a member",
        'organization.manage_billing' => 'Subscribe, change plan, and read billing facts',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array ROLES = [
        'admin' => [
            'organization.view_members',
            'organization.invite',
            'organization.manage_members',
            'organization.manage_billing',
        ],
        'member' => [
            'organization.view_members',
        ],
    ];

    private const string GUARD = 'web';

    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $team = $columns['team_foreign_key'];
        $morph = $columns['model_morph_key'];
        $rolePivot = $columns['role_pivot_key'] ?? 'role_id';
        $permissionPivot = $columns['permission_pivot_key'] ?? 'permission_id';

        Schema::create($tables['permissions'], function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tables['roles'], function (Blueprint $table) use ($team): void {
            $table->id();
            // Null makes a built-in role definition available to every organization.
            $table->foreignId($team)->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique([$team, 'name', 'guard_name']);
        });

        Schema::create($tables['model_has_permissions'], function (Blueprint $table) use ($tables, $team, $morph, $permissionPivot): void {
            $table->unsignedBigInteger($permissionPivot);
            $table->string('model_type');
            $table->unsignedBigInteger($morph);
            $table->foreignId($team)->constrained('organizations')->cascadeOnDelete();

            $table->index([$morph, 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($permissionPivot)
                ->references('id')
                ->on($tables['permissions'])
                ->cascadeOnDelete();

            $table->primary(
                [$team, $permissionPivot, $morph, 'model_type'],
                'model_has_permissions_permission_model_type_primary',
            );
        });

        Schema::create($tables['model_has_roles'], function (Blueprint $table) use ($tables, $team, $morph, $rolePivot): void {
            $table->unsignedBigInteger($rolePivot);
            $table->string('model_type');
            $table->unsignedBigInteger($morph);
            $table->foreignId($team)->constrained('organizations')->cascadeOnDelete();

            $table->index([$morph, 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($rolePivot)
                ->references('id')
                ->on($tables['roles'])
                ->cascadeOnDelete();

            $table->primary(
                [$team, $rolePivot, $morph, 'model_type'],
                'model_has_roles_role_model_type_primary',
            );
        });

        Schema::create($tables['role_has_permissions'], function (Blueprint $table) use ($tables, $rolePivot, $permissionPivot): void {
            $table->unsignedBigInteger($permissionPivot);
            $table->unsignedBigInteger($rolePivot);

            $table->foreign($permissionPivot)
                ->references('id')
                ->on($tables['permissions'])
                ->cascadeOnDelete();

            $table->foreign($rolePivot)
                ->references('id')
                ->on($tables['roles'])
                ->cascadeOnDelete();

            $table->primary([$permissionPivot, $rolePivot], 'role_has_permissions_permission_id_role_id_primary');
        });

        $this->seedCatalog($tables, $team);
        $this->backfillAssignments($tables, $team, $morph, $rolePivot);

        $this->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = config('permission.table_names');

        Schema::dropIfExists($tables['role_has_permissions']);
        Schema::dropIfExists($tables['model_has_roles']);
        Schema::dropIfExists($tables['model_has_permissions']);
        Schema::dropIfExists($tables['roles']);
        Schema::dropIfExists($tables['permissions']);

        $this->forgetCachedPermissions();
    }

    /**
     * organization_id is passed explicitly: the package's Role fills it from the
     * resolved team when absent.
     *
     * @param  array<string, string>  $tables
     */
    private function seedCatalog(array $tables, string $team): void
    {
        $now = now();

        DB::table($tables['permissions'])->insert(
            collect(array_keys(self::PERMISSIONS))
                ->map(fn (string $name): array => [
                    'name' => $name,
                    'guard_name' => self::GUARD,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );

        DB::table($tables['roles'])->insert(
            collect(array_keys(self::ROLES))
                ->map(fn (string $name): array => [
                    $team => null,
                    'name' => $name,
                    'guard_name' => self::GUARD,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );

        $permissionIds = DB::table($tables['permissions'])
            ->where('guard_name', self::GUARD)
            ->pluck('id', 'name');

        $roleIds = DB::table($tables['roles'])
            ->where('guard_name', self::GUARD)
            ->whereNull($team)
            ->pluck('id', 'name');

        $grants = [];

        foreach (self::ROLES as $role => $permissions) {
            foreach ($permissions as $permission) {
                $grants[] = [
                    'role_id' => $roleIds[$role],
                    'permission_id' => $permissionIds[$permission],
                ];
            }
        }

        DB::table($tables['role_has_permissions'])->insert($grants);
    }

    /**
     * Without this, an in-place upgrade leaves every existing member with zero
     * permissions. Unknown ranks are skipped rather than guessed, and model_type comes
     * from the model so rows match what HasRoles writes.
     *
     * @param  array<string, string>  $tables
     */
    private function backfillAssignments(array $tables, string $team, string $morph, string $rolePivot): void
    {
        $morphClass = new User()->getMorphClass();

        $roleIds = DB::table($tables['roles'])
            ->where('guard_name', self::GUARD)
            ->whereNull($team)
            ->pluck('id', 'name');

        DB::table('memberships')
            ->select(['user_id', 'organization_id', 'role'])
            ->orderBy('id')
            ->chunk(500, function ($memberships) use ($tables, $team, $morph, $rolePivot, $roleIds, $morphClass): void {
                $rows = collect($memberships)
                    ->filter(fn (object $membership): bool => isset($roleIds[$membership->role]))
                    ->map(fn (object $membership): array => [
                        $rolePivot => $roleIds[$membership->role],
                        'model_type' => $morphClass,
                        $morph => $membership->user_id,
                        $team => $membership->organization_id,
                    ])
                    ->all();

                if ($rows !== []) {
                    DB::table($tables['model_has_roles'])->insert($rows);
                }
            });
    }

    private function forgetCachedPermissions(): void
    {
        resolve(Factory::class)
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
