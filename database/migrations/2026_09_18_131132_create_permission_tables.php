<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The RBAC store: `spatie/laravel-permission`, team-scoped on the organization.
 *
 * Published from the package and then changed in four ways, each recorded as
 * D29 or D30.
 *
 * **The team key is `organization_id`** and teams are on unconditionally. The
 * package branches on `config('permission.teams')` so one stub can serve both
 * shapes; this application answered that question in D29 and a dead branch in a
 * migration is a second answer nobody will maintain.
 *
 * **Role definitions are global, assignments are team-scoped (D30).** A seeded
 * role carries a null `organization_id` and belongs to every organization; a
 * customer-defined role, if V1 ever grows one, is the same row with an
 * organization in that column. Isolation lives in the assignment tables, which
 * are never null.
 *
 * **Foreign keys the package stub omits.** The stub constrains `role_id` and
 * `permission_id` and stops. Without a constraint on the team key, deleting an
 * organization leaves its assignments behind — and a later organization
 * reusing that key inherits them, which is a cross-tenant grant arriving by
 * way of an auto-increment. `memberships` already cascades; these now match it.
 *
 * `model_id` deliberately gets no constraint. It is one half of a polymorphic
 * pair, and a foreign key on it would declare in the schema that only users
 * ever hold roles. Revoking on account deletion is the application's job:
 * `HasRoles` registers a `deleting` hook for it, and `App\Actions\DeleteUser`
 * owns the audited path.
 *
 * **The catalog is seeded here rather than by a seeder**, so `migrate` alone
 * produces a working system — M7's `saas:demo` lands a developer mid-journey
 * with no manual database step, and a seeder is a manual database step.
 *
 * The names are written literally rather than read from `App\Enums\Permission`
 * and `App\Enums\OrganizationRole`. A migration is a historical record: if the
 * enums are later renamed or a case is added, this file must keep describing
 * the database it actually built. `tests/Unit/PermissionCatalogTest.php` is
 * what stops the two drifting apart.
 *
 * Column types stay portable: Postgres is the documented path, SQLite is the
 * local default, and nothing here costs MySQL anything (D3).
 */
return new class extends Migration
{
    /**
     * The permission catalog as of this migration.
     *
     * Every entry has a caller in M3 or M4. Nothing is added on speculation —
     * D11's filter applies to permissions as much as to features.
     *
     * @var array<string, string>
     */
    private const array PERMISSIONS = [
        'organization.view_members' => 'See who is in this organization, and who has been asked',
        'organization.invite' => 'Invite people, resend and revoke invitations',
        'organization.manage_members' => "Change a member's rank, and remove a member",
        'organization.manage_billing' => 'Subscribe, change plan, and read billing facts',
    ];

    /**
     * Which permissions each role carries.
     *
     * The keys match `App\Enums\MembershipRole`, because D31 makes the
     * assignment a projection of rank rather than a second thing to write.
     *
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
            // Nullable, and null is the normal case: a role definition belongs to
            // every organization until somebody defines one of their own (D30).
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
     * Write the global catalog.
     *
     * `organization_id` is passed explicitly on every row. The package's `Role`
     * model fills that column from the *currently resolved* team when the key
     * is absent, so omitting it would scope the catalog to whichever
     * organization happened to be resolved when the migration ran (D30).
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
     * Give every membership that already exists the role its rank implies.
     *
     * Without this, an application upgraded in place keeps its memberships and
     * loses every ability attached to them: each administrator and each owner
     * silently drops to zero permissions on deploy. On a fresh database the
     * loop simply finds nothing.
     *
     * Ranks that name no role are skipped rather than guessed at. A membership
     * carrying an unknown rank is a data problem, and inventing a role for it
     * here would grant access on the strength of a typo.
     *
     * `model_type` is asked of the model rather than written as a string, so
     * these rows say exactly what `HasRoles` will say when it writes its own.
     * A morph map introduced later changes both, and the rows written before it
     * need a data migration — which is true however this line is spelled.
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
