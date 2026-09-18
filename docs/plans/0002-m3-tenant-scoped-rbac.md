# 0002 — M3, tenant-scoped RBAC

- **Date:** 2026-09-18
- **Status:** Proposed. Reviewed by `/plan-eng-review` including an outside voice; see the
  review report at the end. Revision 2 — revision 1's D29 rationale was wrong and its
  task order was unexecutable.
- **Branch:** `feat/m3-rbac`, cut from `origin/main` at `4040287`.
- **Milestone:** M3 of `plans/0001-vertical-slice.md`.
- **Decisions it rests on:** D3, D11, D15, D20, D21, D22, D23, D24, D26, D27, D28.
- **Decisions it proposes:** D29, D30, D31.
- **Proof it is done:** a role granted in organization A grants nothing in organization
  B — asserted over HTTP, over a queue roundtrip, and over an organization switch inside
  one request. Plus the segment this milestone spends the layer on: an administrator
  removes a member from `/organizations/members`, and the last administrator cannot be
  removed.

## The one sentence

`spatie/laravel-permission` ^8.3 arrives team-scoped with the organization as the team.
`memberships.role` keeps meaning rank and never becomes a second permission store (D23).
What changes is *where the answer to `can()` comes from* — not the shape of any policy.

## What already exists and is not rebuilt

| Existing | What M3 does with it |
| --- | --- |
| `App\Tenancy\TenantContext` (`setId` / `forget` / `runFor` / `runForId` / the cross-tenant runner) | The single funnel for "which organization is this unit of work acting for". spatie needs exactly that signal. It is pushed from inside this class. **No second resolver, no second middleware.** |
| `TenancyServiceProvider`'s `Context::hydrated` listener | Queue propagation already exists (D24). Because the team id is pushed from inside `TenantContext`, it rides D24's mechanism without a new listener. |
| `App\Policies\InvitationPolicy::manages()` | One private method is the entire seam. Its four public methods keep their signatures, and its `owner_id` floor stays (see D31). |
| `App\Tenancy\MembershipRepository::activeMembership()` | The membership lookup authorization needs, already audited past the scope (D22). It also carries the **status** check that `can()` cannot express, so it stays in the policy rather than being replaced by it. |
| `App\Enums\MembershipRole::label()` | Stays the display layer. The new `Permission` enum copies its `label()` shape rather than inventing a second one. |
| `organizations.owner_id` | Ownership stays here. Only *permissions* move. |
| `App\Actions\AddOrganizationMember` | Becomes the single place a membership is created, and therefore the single place a role is assigned. |
| `App\Actions\TransferOrganizationOwnership` | Already moves rank to the new owner in a transaction. It gains the role write in that same transaction — `syncRoles`, not `assignRole`. |
| `tests/Support/TenantQueryGuard` | Extended to watch the spatie pivot tables, rather than replaced — with the limits D29 records. |

Nothing above is rewritten. The only file whose *shape* changes is `InvitationPolicy`,
and only in its private method.

## Decisions this milestone proposes

### D29 — The team foreign key is `organization_id`, and the tenant invariant is asserted directly

`config('permission.column_names.team_foreign_key')` is `organization_id`, not the
package default `team_id`. `config('permission.register_permission_check_method')` is
**`false`**.

**Why the column name:** the codebase has exactly one word for the tenant, and a
migration, a model and a SQL log that all say `organization_id` are readable by the same
person who read D3. That is the whole argument.

**What it is explicitly *not*:** it is not a way to satisfy the D20 query guard.
`tests/Support/TenantQueryGuard.php:126` returns early on *any* SQL containing the string
`organization_id`, before it checks which table was touched. So naming the column
`organization_id` does not make spatie's queries pass the guard truthfully — it makes
them pass unconditionally, including the case that matters: when the registrar's team id
is `null`, `wherePivot` renders `organization_id is null`, which is an unscoped read that
the guard waves through.

**So the guard does not cover this milestone, and M3 supplies its own invariant instead:**

> `PermissionRegistrar::getPermissionsTeamId()` equals `TenantContext::id()` at every
> point the two are both observable — including when both are null.

That is asserted by a listener installed for the whole suite alongside
`TenantQueryGuard`, in the same spirit as D20: a property of runtime state, asserted
against runtime state, for every test rather than the handful written with RBAC in mind.

**Why `register_permission_check_method => false`:** the package default installs a
`Gate::before` that answers every ability before any policy runs. That is a second
authorization idiom, stronger than the `permission:` route middleware this plan already
rules out, and it can short-circuit `MembershipPolicy`'s last-administrator rule if a
permission is ever named to collide with a policy ability. It also turns every
`Gate::authorize('viewAny', …)` into a thrown-and-caught `PermissionDoesNotExist`.
Policies stay the only way an ability is answered.

**Cost accepted:** a published config file this project now owns, so a package upgrade
that reshapes `config/permission.php` has to be read rather than merged. Same cost D28
accepted for the middleware order, for the same reason.

### D30 — Role definitions are global; only assignments are team-scoped

`roles` rows carry a null `organization_id` and are seeded once, by migration. The
per-organization fact lives in `model_has_roles` and `model_has_permissions`, which
always carry the organization.

**Why:** per-team role rows mean `CreateOrganization` writes role rows for every
organization that will ever exist, including every personal one — two rows per user at
registration, for a catalog identical in all of them, on a path whose failure the
registering user cannot act on. Global definitions cost nothing and isolate identically,
because isolation lives in the assignment, not the definition.

**Two package behaviours this binds:**

- `Spatie\Permission\Models\Role::create()` fills the team key from the *currently
  resolved* team unless the key is explicitly present. Every seed statement passes
  `'organization_id' => null` explicitly. It is never omitted and never inferred.
- The package's unique index is `(organization_id, name, guard_name)`, and both Postgres
  and MySQL treat NULLs as distinct — so under D30 the normal case has no database-level
  uniqueness. V1 is safe because roles are created exactly once, by a migration, and
  never at runtime. **A runtime role-creation path may not be added without solving that
  first**, or it reintroduces the check-then-insert race `CreateOrganization`'s docblock
  exists to reject.

**Extension point, decided now rather than improvised later (D15's requirement):** a
customer-defined role is a `roles` row with a non-null `organization_id`. The schema
already permits it and nothing in M3 reads `organization_id is null` as a precondition.
Display names come from the `Permission` enum's `label()` at M3; when roles become
customer-defined, `config('permission.models.role')` is the documented swap to a
subclass. **That swap trips an existing arch test** —
`tests/Unit/TenantScopingTest.php:61` fails any model in `app/Models` carrying an
`organization_id` column that is not `TenantOwned`, and a global role row cannot be
`TenantOwned`. The subclass therefore belongs outside `app/Models`, or that test gains a
recorded exemption at the same time. Written down here so it is a decision when it
arrives rather than a surprise.

### D31 — Rank is the writable fact; the role assignment is a derived projection, and `owner_id` remains the floor

`memberships.role` is written. The `model_has_roles` row is a projection of it, written
in the same transaction, by the same action, with `syncRoles` rather than `assignRole` so
a promotion replaces rather than accumulates. There is no code path that assigns a role
without setting rank.

**Why:** D23 warns that two stores for one fact drift the first time a write half-fails.
M3 is the milestone that creates the second store, so M3 is where the rule against
drifting goes.

**Three things this decision does *not* claim**, each corrected from revision 1:

1. **Rank is not the only fact that gates access.** `memberships.status` is the other,
   and `can()` cannot express it — `model_has_roles` has no notion of a suspended member.
   `InvitationPolicy::manages()` therefore keeps its `activeMembership()` call and asks
   `can()` *in addition*, not *instead*. Today a suspended member fails closed only
   because `ResolveTenantContext` filters to active memberships; M6's suspend transitions
   land directly on this and must not find the check gone.
2. **The `owner_id` short-circuit stays.** Revision 1 deleted it on the theory that the
   owner always holds the admin role. That theory is true by construction and still the
   wrong thing to rely on: a single missing `model_has_roles` row locks an owner out of
   inviting, out of promoting anybody (which needs the permission they just lost), and
   out of every in-app recovery path. `organizations.owner_id` is D23's one indelible
   fact precisely so it survives a drifted projection. The short-circuit is redundant,
   never wrong, and redundancy is what belongs on the one path that can lock a paying
   customer out of their own organization.
3. **The projection is not self-proving.** Under `RefreshDatabase` every membership in
   the database was created by the code under test, so "for every membership the
   assignment matches" is vacuously true. The assertion that earns its keep is the
   **backfill**: a migration that writes assignments for memberships that already exist,
   and a test that runs it against a database seeded *before* the package landed.

**Consequence:** `CreateOrganization` stops inlining its own `Membership::query()->create()`
and calls `AddOrganizationMember`, so there is one writer rather than two.

## Architecture

### Where the team id comes from

The single most important property of this milestone: spatie's active team is
**registrar state, not `Illuminate\Log\Context`**. Nothing dehydrates it into a queue
payload. If it is set anywhere other than inside `TenantContext`, a long-lived worker
authorizes job #2 against job #1's organization — D24's "absence must forget" defect,
a second time, in the authorization layer.

```
                    ┌──────────────────────────────────────────┐
                    │            TenantContext                 │
                    │  the ONLY writer of the active team id   │
                    └──────────────────────────────────────────┘
                                      │
   setId($id) ────────────────────────┼──────────────────────────────┐
        │                             │                              │
        ▼                             ▼                              ▼
  $this->organizationId       Context::add(KEY, $id)      PermissionRegistrar
                                      │                  ::setPermissionsTeamId($id)
                                      │                              │
                                      ▼                              │
                       Queue::createPayloadUsing ──► payload         │
                                      │                              │
                                      ▼                              │
                       JobProcessing ──► Context::hydrate            │
                                      │                              │
                                      ▼                              │
                       TenancyServiceProvider listener               │
                          ├── key present ──► setId($id) ────────────┘
                          └── key ABSENT  ──► forget()
                                                 │
                                                 ├── organizationId = null
                                                 ├── Context::forget(KEY)
                                                 └── setPermissionsTeamId(null)   ◄── the line
                                                                                      M3 exists to
                                                                                      get right
```

`forget()` nulling the team id is not symmetry for its own sake. Without it the registrar
keeps the previous organization and `can()` answers for a tenant that is no longer
resolved, while every *query* is correctly scoped — an authorization leak with no query
leak, which the D20 guard cannot see. D29's invariant listener is what sees it.

### Where the answer comes from

```
  Controller / Inertia prop
        │  Gate::authorize('create', Invitation::class)
        ▼
  InvitationPolicy::manages()          ◄── shape unchanged; body extended
        │
        ├── owner_id === $user->id ? ──────────────► true     (D31 floor, never removed)
        │
        ├── activeMembership($user, $org) ? ──no──► false     (status, which can() cannot express)
        │
        └── $user->can(Permission::InviteMembers->value)
                    │
                    ▼
            model_has_roles / model_has_permissions
            WHERE organization_id = <resolved tenant>
                    │
                    ▼
                true / false
```

And the relationship between the two stores, which D31 fixes in one direction:

```
   memberships.role        ──derived──►   model_has_roles
   (rank, D23, writable)   one txn        (permission, projection)
        ▲                                        │
        │                                        │ never written alone
        └────────────── no path back ────────────┘
```

## Scope

### In scope

**Lane A — the mechanism.**

1. `composer require spatie/laravel-permission:^8.3` (D7's floor is satisfied:
   `illuminate/* ^12.0|^13.0`, `php ^8.3`). **This is task one — every other task in this
   lane edits or reads code that does not exist until it runs.**
2. Publish `config/permission.php`; `teams => true`;
   `column_names.team_foreign_key => 'organization_id'`;
   `register_permission_check_method => false` (D29).
3. The package migration, renamed into this project's dated convention, with: the team
   column nullable on `roles` and non-null on both pivots (D30); and **foreign keys the
   package stub omits** — `organization_id` cascading on organization delete, `model_id`
   cascading on user delete. Without them, deleting an organization orphans its
   assignments and D31's projection test cannot see the orphans, because it reads from
   memberships outward.
4. A **backfill** in the same migration: an assignment for every membership that already
   exists, derived from its rank (D31.3).
5. `App\Enums\Permission` — a backed enum with a `label()`, shaped like
   `MembershipRole::label()`. Three cases, each with a caller in this milestone:
   `organization.invite`, `organization.manage_members`, `organization.manage_billing`
   (M4's first screen, one milestone away and already on the D8 journey). Nothing else is
   guessed (D11).
6. `App\Enums\OrganizationRole` — `Admin` / `Member`, with a
   `permissions(): list<Permission>` matrix. This is the file a reviewer reads to learn
   who may do what.
7. Seed the catalog from the migration with an explicit `'organization_id' => null` on
   every row (D30), so a fresh `migrate` produces a working system with no seeder step —
   M7's `saas:demo` depends on that.
8. `TenantContext::setId()` / `forget()` push and null the registrar team id.
9. `User` uses `Spatie\Permission\Traits\HasRoles`.
10. `DeleteUser` gets a named audited door. `HasRoles` registers a `deleting` hook that
    turns team scoping **off process-wide**, detaches roles across all teams, and turns it
    back on — which emits an unscoped delete against a watched table, and leaves teams
    disabled for the rest of the process if it throws. Revoking across every team is the
    *correct* behaviour on account deletion, so this is a fourth audited exception in the
    shape D22 and D27 established, not a bug to suppress.
11. `tests/Pest.php`: register the two pivot tables with the query guard, install D29's
    invariant listener, flush the permission cache per test.

**Lane B — the writers (D31).**

12. `AddOrganizationMember` writes rank and syncs the role in one transaction, inside
    `runForId()` so the registrar has a team.
13. `CreateOrganization` calls `AddOrganizationMember` instead of inlining
    `Membership::query()->create()`.
14. `TransferOrganizationOwnership` syncs the role alongside the rank. Its current bulk
    `->update(['role' => Admin])` fires no model events, so the role side is written
    explicitly, per user.
15. `App\Actions\ChangeOrganizationMemberRole` — new, because promoting a member is now
    two writes that must not diverge, and the members screen needs it.

**Lane C — the consumers.**

16. `InvitationPolicy::manages()` extended to ask `can()` after the owner floor and the
    status check (D31.1, D31.2).
17. `App\Policies\MembershipPolicy` — `delete()` and `update()`. The rules M3 introduces:
    an administrator may remove a member; nobody may remove the owner; the last
    administrator may not be removed or demoted.
18. `App\Actions\RemoveOrganizationMember` — revokes role and membership in one
    transaction, refusing the last administrator and the owner with named exceptions in
    `App\Exceptions\Memberships\`, the shape M2 established.
19. `MemberController::destroy` and the role-change endpoint, routes, and the Vue actions
    on `resources/js/pages/organizations/Members.vue`.
20. `HandleInertiaRequests` shares the current user's permissions as an explicit, flat
    array so the UI can hide what it may not do — chrome, never enforcement. It must also
    **not** leak them accidentally: `share()` serializes the same `User` instance a
    policy call may have hydrated `roles` and `permissions` onto, pivot data included. The
    shared user is presented explicitly rather than passed whole.
21. The relation-staleness fix lives at the authorization seam, not in `TenantContext`.
    After an organization switch, a `User` already holding `roles` / `permissions` for the
    previous team answers `can()` from stale data. `TenantContext` holds no user, has no
    auth dependency, and is null-user in a queue worker — it cannot fix this. The policy
    (and any other `can()` caller) unsets both relations when the resolved organization
    differs from the one they were loaded under.

**Lane D — the proof and the record.**

22. Tests, below.
23. D29–D31 into `docs/decisions/0001-architecture-decisions.md`; `plans/0001` marks M3
    done against its journey segment; `TODOS.md` loses the member-removal entry and gains
    the deploy note for `permission:cache-reset`.

### NOT in scope

| Deferred | Why |
| --- | --- |
| Customer-defined roles and a role-editing UI | D11's filter: the journey never defines a role. D30 records the schema-level extension point *and* the arch test it will trip. |
| `display_name` columns on `roles` / `permissions` | D15 anticipated this as the usual first need; the enum's `label()` answers it for a fixed catalog at zero cost. |
| `organization.manage_settings` | Revision 1 listed it and then, three lines later, cited D11 against guessing. It has no caller in M3. |
| Permission checks in Filament admin | M6 owns admin. An `admin.*` permission now guesses at a surface nobody has designed. |
| `Suspend` / `Archive` / `Restore` transitions | Still M6. D31.1 records that they land on the status check, so it must not be removed in the meantime. |
| Wildcard permissions, `permission:` route middleware, and the package's `Gate::before` | Policies are the one way an ability is answered. D29 turns the third one off explicitly. |
| Counting a pending invitation against seats | Already in `TODOS.md`, blocked on M4/M5, unaffected. |
| Superseded invitation history | Already in `TODOS.md`, blocked on M6's audit log. |
| Reassigning a removed member's data | `Project` has no per-user ownership column. Nothing to reassign until one exists. |

**Checked against M4:** nothing above is load-bearing for Cashier on `Organization`. M4
needs one permission (`organization.manage_billing`, which ships here) and the
`owner_id` fact D23 already guarantees.

## Tests

### Coverage diagram

```
CODE PATHS                                             USER FLOWS
[+] app/Tenancy/TenantContext.php                      [+] Invite a teammate (M2 journey segment)
  ├── setId() → registrar team id set                    ├── [★★★] admin invites — InviteATeammateTest
  │   └── [GAP] registrar id == TenantContext::id()       └── [GAP] member (non-admin) is refused
  ├── forget() → registrar team id NULLED
  │   └── [GAP] CRITICAL — the leak with no query leak  [+] Members screen
  ├── runForId() restores previous team id               ├── [GAP] [→E2E] admin sees Remove, member does not
  │   └── [GAP] nested + restore-to-null                 ├── [GAP] [→E2E] admin removes a member
  └── cross-tenant runner → team id null in the body     ├── [GAP] remove the last administrator → refused
      └── [GAP]                                          ├── [GAP] remove the owner → refused
                                                         └── [GAP] demote the last administrator → refused
[+] tests/Pest.php — D29 invariant listener
  └── [GAP] fails a test that desyncs the two           [+] Cross-organization isolation (THE PROOF)
                                                          ├── [GAP] admin in A, member in B: can() flips on switch
[+] app/Enums/OrganizationRole.php                        ├── [GAP] [→E2E] switch mid-request, stale roles relation
  └── [GAP] every case has ≥1 permission                  ├── [GAP] stale PERMISSIONS relation (second relation)
                                                          └── [GAP] queue: job for B never sees A's team id
[+] migration — backfill + FKs
  ├── [GAP] CRITICAL backfill: pre-existing memberships  [+] Registration
  │         get assignments                                └── [★★★] personal org created — PersonalOrganizationTest
  ├── [GAP] org delete cascades assignments                   └── [GAP] owner holds the admin role afterwards
  └── [GAP] user delete cascades assignments
                                                         [+] Account deletion
[+] app/Actions/AddOrganizationMember.php                  └── [★★★] refused when solely owning — OwnershipTest
  ├── [GAP] projection holds (rank → role)                    └── [GAP] REGRESSION — the deleting hook's unscoped
  └── [GAP] rollback leaves neither row                           detach vs the query guard

[+] app/Actions/CreateOrganization.php                   [+] Error states
  └── [GAP] REGRESSION — slug-collision retry loop         ├── [GAP] remove refused → named message, screen recovers
      survives delegation                                  └── [GAP] 403 on a member hitting destroy directly

[+] app/Actions/TransferOrganizationOwnership.php
  └── [GAP] REGRESSION — syncRoles, not assignRole
      (no leftover member role)

[+] app/Actions/RemoveOrganizationMember.php (new)
  ├── [GAP] happy path
  ├── [GAP] last administrator → LastAdministrator
  ├── [GAP] owner → OwnerCannotBeRemoved
  └── [GAP] [→PGSQL] concurrent removal of the last two admins

[+] app/Policies/InvitationPolicy.php
  ├── [GAP] REGRESSION — owner passes via the floor
  ├── [GAP] suspended member is refused (status, not rank)
  └── [GAP] admin with a missing assignment: owner recovers,
            non-owner admin fails closed

[+] app/Policies/MembershipPolicy.php (new)
  └── [GAP] delete/update × owner/admin/member/outsider

[+] app/Actions/DeleteUser.php
  └── [GAP] REGRESSION — audited door, and teams scoping
            is still on afterwards

COVERAGE: 4/41 paths tested (10%)  |  Code paths: 1/24  |  User flows: 3/17
QUALITY: ★★★:4  |  GAPS: 37 (3 E2E, 1 Postgres-only, 6 REGRESSION, 2 CRITICAL)
```

Legend: ★★★ behavior + edge + error · ★★ happy path · ★ smoke · [→E2E] browser test ·
[→PGSQL] cannot be proven on SQLite

### Test files

| File | What it proves |
| --- | --- |
| `tests/Feature/Authorization/RoleIsolationTest.php` | **The milestone's proof.** Admin in A, plain member in B. `can()` is true in A and false in B, across a switch, an explicit `runFor()`, and a queue roundtrip. Includes the stale-relation cases for *both* `roles` and `permissions`. |
| `tests/Feature/Authorization/TenantContextTeamTest.php` | `setId` sets, `forget` nulls, `runForId` restores including restoring to null, the cross-tenant runner leaves no team resolved. |
| `tests/Feature/Authorization/RolePersistenceTest.php` | D31's projection, and the backfill run against a database seeded before the package landed. Rollback leaves neither row. |
| `tests/Feature/Authorization/AssignmentLifecycleTest.php` | The foreign keys: deleting an organization and deleting a user each take their assignments with them. |
| `tests/Feature/Organizations/MemberRemovalTest.php` | Removal, the last-administrator refusal, the owner refusal, the 403 for a plain member. The concurrent double-removal is tagged to run on Postgres only — `lockForUpdate` is a no-op on SQLite, and "at least one administrator remains" is not expressible as a unique index, so the precedent in `InvitationBoundaryTest` does not transfer. |
| `tests/Feature/Organizations/MemberRoleChangeTest.php` | Promotion and demotion, the last-administrator demotion refusal, and that a promoted member holds exactly one role. |
| `tests/Unit/PermissionCatalogTest.php` | Every `OrganizationRole` case maps to at least one `Permission`; every seeded row matches the enums; every seeded role has a null `organization_id`; no orphan permission rows. |
| `tests/Browser/MembersManagementTest.php` | The `[→E2E]` flows: an administrator sees and uses Remove; a plain member sees neither the button nor a working endpoint. |
| Amendments to `tests/Pest.php` | Register both pivot tables with the query guard; install the D29 invariant listener; flush the permission cache. |
| Amendments to `tests/Feature/Settings/ProfileUpdateTest.php` | Regression: `DeleteUser` still works with the guard watching, through its named door, and team scoping is on afterwards. |
| Amendments to `tests/Feature/Invitations/InvitationEndpointTest.php` | Regression: the owner floor; a suspended member refused. |
| Amendments to `tests/Feature/Organizations/OwnershipTest.php` | Regression: `syncRoles` on transfer, no leftover role. |
| Amendments to `tests/Feature/Organizations/PersonalOrganizationTest.php` | Regression: the slug-collision retry loop survives the delegation. |

### Test traps this suite will hit

Recorded so the implementation does not rediscover them. Items 2–5 are prior learnings on
this repository; 1 and 6 came out of this review.

1. **`HasRoles`'s `deleting` hook sets `teams = false` process-wide** while it detaches,
   then restores it. The detach is an unscoped delete against a watched table, and a throw
   mid-hook leaves team scoping globally off — in a queue worker, for every subsequent
   job. `DeleteUser` needs the named door, and a test that asserts scoping is back on.
2. **`ShouldBeStrict` is on app-wide** (`config/essentials.php:165`). This turned out
   *not* to bite `can()`: `hasPermissionTo()` and `hasRole()` both call `loadMissing()`,
   which is explicit eager loading, so `preventLazyLoading` never fires. It still binds
   anything that reads `$membership->user` or `$invitation->organization` — unchanged
   from M2.
3. **The stale relations.** After a switch inside one request or job, both `roles` *and*
   `permissions` are the previous team's. `forgetCachedPermissions()` clears the registrar
   cache, not a loaded Eloquent relation. Fixed at the authorization seam (scope item 21),
   because `TenantContext` cannot reach the user.
4. **`tests/Unit/TenantScopingTest.php` greps `app/` for the literal strings
   `withoutTenantScope` and `runWithoutTenant`** — including inside docblocks. M3's new
   files must not name either, even when explaining themselves.
5. **Queue test ordering.** Proving a worker forgets the team between jobs requires the
   no-context job to be *dispatched before* the tenant-carrying job is worked. Dispatch it
   afterwards and it captures the tenant into its own payload and passes for the wrong
   reason.
6. **The permission cache versus `RefreshDatabase`.** spatie caches the permission map in
   the cache store; the suite rolls the database out from under it. Without a flush in
   `tests/Pest.php` the suite fails in an order-dependent way that looks like flake.

## Failure modes

| Codepath | Realistic production failure | Test? | Handled? | User sees |
| --- | --- | --- | --- | --- |
| `TenantContext::forget()` | Worker keeps the previous team; job authorizes against the wrong organization | Planned | Yes, once `forget()` nulls it | **Silent** — correct queries, wrong answer. The D20 guard cannot see it; D29's invariant listener can. |
| Existing database, no backfill | Every current member and owner drops to zero permissions on deploy | Planned | Yes, backfill migration | Visible and total: the product stops working for everyone at once |
| Missing assignment for one member | That member silently loses every ability | Planned | Owner recovers via the D31 floor; a non-owner administrator does not | Visible: blanket 403s, recoverable only by an owner |
| Organization or user deleted | Orphan rows in both pivots; a recreated id inherits stale grants | Planned | Yes, foreign keys the package stub omits | Silent until it is a cross-tenant grant |
| `DeleteUser`'s detach throws | Team scoping left off process-wide for every later job on that worker | Planned | Restore in a `finally`, plus the door | **Silent cross-tenant authorization** |
| Stale `roles` / `permissions` after a switch | Organization B's screens answered with A's permissions | Planned | Yes, at the authorization seam | Visible: a button that 403s |
| `AddOrganizationMember` partial write | Membership without assignment | Planned | Yes, one transaction | Visible: silent 403s on every action |
| `RemoveOrganizationMember` concurrency | Two administrators remove each other; the organization ends with none | Partial | `lockForUpdate` + re-read | Visible on Postgres; **unprovable on SQLite** |
| Permission cache after a deploy | Cached map predates a catalog change | No | `php artisan permission:cache-reset` in the deploy notes | Visible: blanket 403s |
| Inertia leaks loaded relations | Role and permission rows, with pivots, serialized into page props | Planned | Present the shared user explicitly | Silent |

Rows one, two and five are the milestone's critical gaps and the reason lane A is
sequenced before everything else.

## Parallelization

| Step | Modules touched | Depends on |
| --- | --- | --- |
| A — mechanism | `composer.json`, `config/`, `database/migrations/`, `app/Enums/`, `app/Tenancy/`, `app/Models/User.php`, `app/Actions/DeleteUser.php`, `tests/Pest.php` | — |
| B — writers | `app/Actions/` | A |
| C — consumers | `app/Policies/`, `app/Http/`, `routes/`, `resources/js/` | A, B |
| D — docs | `docs/`, `TODOS.md` | A, B, C |

`Lane A → Lane B → Lane C → Lane D.` **Sequential implementation, no parallelization
opportunity** — every lane reads the team id lane A installs, and lanes B and C both write
through `app/Actions/`. Splitting this across worktrees buys a merge conflict.

## Implementation tasks

P1 blocks the milestone; P2 lands on the same branch; P3 is a follow-up. Order is
executable as written — revision 1's first three tasks were not.

- [ ] **T1 (P1, human: ~1h / CC: ~10min)** — config — Install `spatie/laravel-permission:^8.3`;
      publish config with `teams`, `organization_id`, and
      `register_permission_check_method => false`
  - Surfaced by: D29 — the package's `Gate::before` is a second authorization idiom
  - Files: `composer.json`, `config/permission.php`
  - Verify: `php artisan about` lists the package; `config('permission.teams') === true`
- [ ] **T2 (P1, human: ~3h / CC: ~25min)** — migration — Package tables with the team key,
      the foreign keys the stub omits, the global-role seed, and the backfill
  - Surfaced by: Outside voice — orphan rows; and D31.3 — the projection is vacuous
        without a backfill
  - Files: `database/migrations/`
  - Verify: `php artisan migrate:fresh` on SQLite and Postgres; `RolePersistenceTest`
- [ ] **T3 (P1, human: ~2h / CC: ~15min)** — tenancy — Push and null the registrar team id
      from `TenantContext::setId()` / `forget()`
  - Surfaced by: Architecture — spatie's team is registrar state, not `Log\Context`
  - Files: `app/Tenancy/TenantContext.php` (update its ASCII diagram in the same commit)
  - Verify: `vendor/bin/pest tests/Feature/Authorization/TenantContextTeamTest.php`
- [ ] **T4 (P1, human: ~2h / CC: ~15min)** — tests — D29's invariant listener, both pivots
      registered with the query guard, permission cache flushed per test
  - Surfaced by: D29 — the guard's `organization_id` early return means it cannot see spatie
  - Files: `tests/Pest.php`, `tests/Support/`
  - Verify: `php artisan test --compact`
- [ ] **T5 (P1, human: ~1h / CC: ~10min)** — actions — Named audited door for `DeleteUser`'s
      cross-team detach; restore team scoping in a `finally`
  - Surfaced by: Outside voice — `HasRoles::bootHasRoles()` disables teams process-wide
  - Files: `app/Actions/DeleteUser.php`, `tests/Unit/TenantScopingTest.php` (allowed list)
  - Verify: `vendor/bin/pest tests/Feature/Settings/ProfileUpdateTest.php`
- [ ] **T6 (P1, human: ~2h / CC: ~15min)** — enums — `Permission` (three cases) and
      `OrganizationRole` with the matrix
  - Surfaced by: Architecture — the file a reviewer reads to learn who may do what
  - Files: `app/Enums/Permission.php`, `app/Enums/OrganizationRole.php`
  - Verify: `vendor/bin/pest tests/Unit/PermissionCatalogTest.php`
- [ ] **T7 (P1, human: ~3h / CC: ~20min)** — actions — One writer for rank + role (D31),
      `syncRoles` not `assignRole`; `CreateOrganization` delegates to `AddOrganizationMember`
  - Surfaced by: Code quality — two membership writers is two places to bolt assignment on
  - Files: `app/Actions/AddOrganizationMember.php`, `CreateOrganization.php`,
    `TransferOrganizationOwnership.php`, `ChangeOrganizationMemberRole.php`
  - Verify: `vendor/bin/pest tests/Feature/Authorization tests/Feature/Organizations`
- [ ] **T8 (P1, human: ~2h / CC: ~15min)** — policies — Extend `InvitationPolicy::manages()`:
      owner floor, then status, then `can()`; unset stale relations at the seam
  - Surfaced by: D31.1 and D31.2 — `can()` cannot express status, and the floor is the
        anti-lockout path
  - Files: `app/Policies/InvitationPolicy.php`
  - Verify: `vendor/bin/pest tests/Feature/Invitations`
- [ ] **T9 (P1, human: ~3h / CC: ~25min)** — authorization — The proof test: a role granted
      in A grants nothing in B, over HTTP, a switch, and a queue roundtrip, for both relations
  - Surfaced by: the build plan's proof sentence for M3
  - Files: `tests/Feature/Authorization/RoleIsolationTest.php`
  - Verify: `vendor/bin/pest tests/Feature/Authorization`
- [ ] **T10 (P1, human: ~4h / CC: ~30min)** — members — `MembershipPolicy`,
      `RemoveOrganizationMember`, named exceptions, the Postgres-tagged concurrency test
  - Surfaced by: `TODOS.md`, and the outside voice's point that SQLite cannot prove it
  - Files: `app/Policies/MembershipPolicy.php`, `app/Actions/`, `app/Exceptions/Memberships/`
  - Verify: `vendor/bin/pest tests/Feature/Organizations/MemberRemovalTest.php`
- [ ] **T11 (P2, human: ~3h / CC: ~25min)** — ui — Remove and role-change on the members
      screen; present the shared user explicitly so loaded relations cannot leak into props
  - Surfaced by: Test review (two E2E flows) and the outside voice (accidental prop channel)
  - Files: `MemberController.php`, `routes/web.php`, `Members.vue`, `HandleInertiaRequests.php`
  - Verify: `vendor/bin/pest tests/Browser/MembersManagementTest.php`
- [ ] **T12 (P2, human: ~1h / CC: ~10min)** — docs — D29–D31, mark M3 done, drop the
      member-removal TODO, add the `permission:cache-reset` deploy note
  - Surfaced by: Failure modes — the cache row has no test and no handling
  - Files: `docs/decisions/0001-architecture-decisions.md`, `docs/plans/0001-vertical-slice.md`, `TODOS.md`
  - Verify: `composer ci:check`

## Inline diagrams the implementation should carry

Per this repository's existing habit (`TenantContext`, `AcceptOrganizationInvitation`,
`ResolveTenantContext` all carry one):

- `app/Tenancy/TenantContext.php` — **update the existing diagram in the same commit as
  T3.** It currently shows two outputs from `setId()`; M3 adds a third. A stale diagram
  here is worse than none, because it is the file that documents the propagation
  mechanism.
- `app/Enums/OrganizationRole.php` — the role → permission matrix as a table.
- `app/Actions/RemoveOrganizationMember.php` — the refusal ladder, in the shape
  `AcceptOrganizationInvitation` established.
- `app/Policies/InvitationPolicy.php` — the three-step ladder (floor, status, permission).
- `app/Policies/MembershipPolicy.php` — the who-may-remove-whom grid.

## GSTACK REVIEW REPORT

| Runs | Status | Findings |
| --- | --- | --- |
| Step 0 scope challenge | complete | Complexity gate tripped (14+ files, 4+ new classes); scope decided as full M3 including member removal |
| 1. Architecture | complete | 8 findings — registrar state vs `Log\Context`, the guard's blind spot, global vs per-team roles, the owner floor, two membership writers, `Gate::before`, missing foreign keys, no backfill |
| 2. Code quality | complete | 3 findings — `CreateOrganization` duplicates `AddOrganizationMember`; `assignRole` accumulates where `syncRoles` replaces; `manage_settings` had no caller |
| 3. Tests | complete | 37 gaps, 6 regressions, 2 critical, 1 unprovable on SQLite |
| 4. Performance | complete | No issues. `can()` reads two already-cached relations per request; the catalog is 3 permissions × 2 roles; `MemberController` remains paginated and eager-loaded |
| Outside voice (Codex) | **unavailable** | `ERROR: You've hit your usage limit` — not a pass |
| Outside voice (Claude subagent) | complete | 18 findings; 15 accepted into revision 2, 1 partially accepted, 2 declined |

**CODEX: unavailable.** Usage limit, not a clean run. Recorded as unavailable rather than
passing, per the prior learning `gstack-outside-voice-double-fallback`.

**CROSS-MODEL absorbed:** 15 of 18 outside-voice findings are in revision 2 — D29's
rationale rewritten, `register_permission_check_method` off, the T1/T2/T3 ordering fixed,
backfill and foreign keys added, `model_has_permissions` tracked, the `DeleteUser` hook
given a door, the stale-relation fix moved out of `TenantContext`, `syncRoles`, the
status check kept, the owner floor restored, the concurrency test scoped to Postgres,
`manage_settings` cut, the arch-test collision recorded on the extension point.

**VERDICT: revision 2 is buildable as written.** Revision 1 was not — its first three
tasks referenced a package that task three installed, and its central decision (D29)
argued from a false premise about what the query guard checks.

**UNRESOLVED DECISIONS:**

- **D15 itself.** The outside voice argues the package is not worth its cost at M3, since
  D31 makes every assignment a pure function of `memberships.role` and the package's own
  global mutable team state is the source of the milestone's hardest problem. This review
  raised the same challenge at Step 0 and it was declined in favour of full M3 on spatie.
  Recorded, not reopened. If it is ever revisited, the cheapest moment is before T2 writes
  a migration.
- **Member removal's dependency on M3.** `TODOS.md` says removal is blocked on the role
  source of truth moving to spatie. The outside voice notes that only one line of T10
  touches spatie, and the administrator count it needs is read from `memberships.role`
  either way. The dependency is weaker than `TODOS.md` claims. It does not change this
  plan, because removal ships here regardless.
