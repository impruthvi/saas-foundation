<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an organization and the membership that makes its owner a member of it.
 *
 * Ownership is `organizations.owner_id`; the membership carries rank, which for
 * an owner is Admin, because MembershipRole has no Owner case (D23).
 *
 * Slug uniqueness is settled by the unique index rather than by a lookup first.
 * Two people registering at the same moment with the same name is the ordinary
 * case, not the exotic one, and check-then-insert loses that race.
 */
final readonly class CreateOrganization
{
    /**
     * The number of suffixed slugs to try before giving the collision back.
     */
    private const int SLUG_ATTEMPTS = 5;

    public function handle(User $owner, string $name, bool $personal = false): Organization
    {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($owner, $name, $personal, $attempt): Organization {
                    $organization = Organization::query()->create([
                        'name' => $name,
                        'slug' => $this->slug($name, $attempt),
                        'personal' => $personal,
                        'owner_id' => $owner->id,
                        'status' => OrganizationStatus::Active,
                    ]);

                    Membership::query()->create([
                        'organization_id' => $organization->id,
                        'user_id' => $owner->id,
                        'role' => MembershipRole::Admin,
                        'status' => MembershipStatus::Active,
                        'joined_at' => now(),
                    ]);

                    return $organization;
                });
            } catch (QueryException $exception) {
                $attempt++;

                throw_if($attempt >= self::SLUG_ATTEMPTS || ! $this->isSlugCollision($exception), $exception);
            }
        }
    }

    /**
     * The first attempt reads from the name; later ones earn a suffix.
     */
    private function slug(string $name, int $attempt): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'organization';
        }

        return $attempt === 0
            ? $base
            : $base.'-'.Str::lower(Str::random(6));
    }

    private function isSlugCollision(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'unique') && str_contains($message, 'slug');
    }
}
