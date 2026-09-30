<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MembershipRank;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slug collisions are handled after the unique index rejects the insert, avoiding a
 * check-then-insert race.
 */
final readonly class CreateOrganization
{
    private const int SLUG_ATTEMPTS = 5;

    public function __construct(private AddOrganizationMember $members) {}

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

                    $this->members->handle($organization, $owner, MembershipRank::Admin);

                    return $organization;
                });
            } catch (QueryException $exception) {
                $attempt++;

                throw_if($attempt >= self::SLUG_ATTEMPTS || ! $this->isSlugCollision($exception), $exception);
            }
        }
    }

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
