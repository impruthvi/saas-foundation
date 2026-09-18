<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A named bundle of permissions, granted inside one organization.
 *
 * Deliberately a second enum rather than methods on `MembershipRole`. D23 says
 * a membership's role is rank and must never become a second permission store,
 * and an enum that answered "what may this rank do" would be exactly that in
 * everything but storage. This names the `roles` rows; `MembershipRole` names
 * the rank; `forRank()` is the one place they meet, which is what makes the
 * assignment a projection rather than a parallel fact (D31).
 *
 * The cases are one-to-one with `MembershipRole` today, and that is a fact to
 * check rather than a coincidence to rely on: the catalog test fails if a rank
 * is ever added without deciding what it may do.
 *
 *   ┌────────────────────┬──────────────────────────────────────────────────┐
 *   │ Admin              │ view members · invite · manage members · billing │
 *   │ Member             │ view members                                     │
 *   └────────────────────┴──────────────────────────────────────────────────┘
 *
 * Ownership is not here and never will be. It is `organizations.owner_id`, one
 * writable fact, and `MembershipRole` has no Owner case for the same reason.
 */
enum OrganizationRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    /**
     * The role a membership of this rank holds.
     *
     * The single point of translation between the writable fact and its
     * projection. Every writer of a membership goes through here, so there is
     * no path that grants a role without setting the rank it came from.
     */
    public static function forRank(MembershipRole $rank): self
    {
        return match ($rank) {
            MembershipRole::Admin => self::Admin,
            MembershipRole::Member => self::Member,
        };
    }

    /**
     * Every role, as the strings the package stores.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * The permissions this role carries.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                Permission::ViewMembers,
                Permission::InviteMembers,
                Permission::ManageMembers,
                Permission::ManageBilling,
            ],
            self::Member => [
                Permission::ViewMembers,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Member => __('Member'),
        };
    }
}
