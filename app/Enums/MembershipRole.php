<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A member's rank inside an organization.
 *
 * There is deliberately no Owner case. Ownership is `organizations.owner_id`,
 * one writable fact, and deriving it from a role would make two (D23). When
 * `spatie/laravel-permission` arrives team-scoped at M3 it owns permissions;
 * this stays rank and never becomes a second permission store.
 */
enum MembershipRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}
