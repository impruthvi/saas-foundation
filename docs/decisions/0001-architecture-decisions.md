# 0001 — Architecture decisions

- **Date:** 2026-09-08, amended 2026-09-16 (D20-D26 for M1) and 2026-09-17 (D27-D28 for M2)
- **Status:** Accepted
- **Scope:** the technical decisions that bind this codebase.

Commit messages in this repository cite these by number. This record exists so that a
number in `git log` can be resolved without asking anyone.

Product strategy, competitive analysis and the project's launch history live in a
separate private planning repository and are deliberately not reproduced here. Where a
decision below was driven by that reasoning, the _engineering_ consequence is stated and
the commercial argument is not.

## D1 — The organization is always the tenant and the billing boundary

No personal subscriptions. A solo user gets a personal organization created
automatically at registration; the UI hides the workspace switcher for them.
`Subscription` belongs to `Organization`, never polymorphically to `User` or
`Organization`.

**Why:** two subject types means two entitlement-resolution paths, two policy shapes, and
a permanent `if ($subject instanceof User)` in the hottest code in the product. The
failure this prevents is concrete and common: teams bolted on beside a `user_id`-scoped
`SubscriptionController`, so billing and membership never meet.

## D2 — `cashier-dunning` is a dependency, not a part of this repository

`impruthvi/cashier-dunning` keeps its own repository and releases. This project depends
on it in `require-dev` and uses it to prove the billing path survives failed payments,
retries, cancellation and out-of-order webhooks.

## D3 — PostgreSQL primary, one database, shared schema, `organization_id` scoping

Postgres is the documented path. Core migrations avoid Postgres-only types so MySQL
keeps working where that costs nothing. SQLite remains the zero-config local default and
CI proves both on every push.

No database-per-tenant and no multi-region in V1. **Tenant scoping is enforced at the
model and repository boundary, not in controllers.**

## D4 — Filament for admin, as a bounded exception to the one-frontend rule

V1 ships two frontend runtimes: Inertia + Vue for the product, Livewire + Blade for
admin. Bounded by a hard rule: **no Livewire in the product surface, no Inertia in admin,
and Filament resources call application services only — zero business rules.**

Admin must remain removable without dead navigation or broken tests. `laravel/chisel` is
the mechanism (see `0002`).

## D5 — Inertia + Vue, one frontend stack

Not React, not Livewire, not multiple variants. Maintaining two frontends is the most
reliable way to ship neither with documentation.

## D6 — Fortify, not Jetstream; Organizations written here

Base conventions: Laravel 13, PHP 8.4, Inertia 3, Fortify, Pest 5, Wayfinder.

The Organizations module — organization, membership, invitation, tenant context,
lifecycle states, ownership transfer — is written for this project. Jetstream's teams are
user-scoped and the wrong shape for D1, and the required behaviour (lifecycle states,
ownership transfer, expiring auditable invitations, a tenant-context resolver that
propagates into queued jobs and webhook processing) does not exist in it.

**Cost accepted:** the invitation flow and team-management UI are built from scratch.

## D7 — Verified dependency floor

Laravel `^13.0`, PHP `^8.4`, `laravel/cashier ^16.8`, `inertiajs/inertia-laravel ^3.3`,
`filament/filament ^5.8`, Pest 5, Larastan, Pint, GitHub Actions.

## D8 — The ten-minute journey is one artifact with three jobs

> Register → personal organization auto-created → invite a teammate → subscribe to Pro
> via Stripe Checkout in test mode → create projects until the plan limit is hit and the
> upgrade prompt appears → open admin and inspect the resolved entitlement, the usage
> counter, and the webhook event that set it.

Simultaneously the README demo, the V1 definition-of-done, and an automated end-to-end
test. One seeded command (`php artisan saas:demo`) must land a developer mid-journey
with no manual database edits.

**If the journey cannot be demonstrated, the test is already failing.**

## D9 — MIT, with day-one governance

`LICENSE.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, `CHANGELOG.md`,
semver, and this directory, from day one.

Deferred until a first external contributor exists: the public RFC process, the
deprecation policy, and a published release cadence. A public RFC process with zero
contributors is theatre, and an announced cadence that slips costs more credibility than
never announcing one.

## D10 — GitHub-only distribution; no Packagist submission

Installed via `laravel new <name> --using=impruthvi/saas-foundation`. PHP namespaces stay
vendor-neutral (`App\`).

**Why:** a starter kit is `"type": "project"` — nobody `composer require`s it, so
Packagist buys only a vendor name that cannot later change without permanently splitting
install statistics.

## D11 — V1 exclusions (hard scope gate)

Multiple frontend variants · additional payment providers · database-per-tenant and
multi-region tenancy · marketplace or plugin store · advanced tax, invoicing and revenue
recognition · enterprise SSO and SCIM · full analytics suite · AI platform · speculative
package extraction · a web configurator (CLI wizard first).

**Scope filter:** if a capability does not strengthen the ten-minute journey (D8) or
validate a core boundary, document the extension point and defer the implementation.

## D12 — One-maintainer support promise

_Issues triaged weekly; security reports acknowledged within 7 days; no response-time
SLA; no LTS; releases when ready._

**Consequence, and it binds:** V1 scope must be operable by one person. Anything that
starts to look like it needs a second maintainer gets cut immediately, not in week seven.

## D15 — RBAC uses `spatie/laravel-permission`, team-scoped

The organization is the team. Expect to extend it; the extension points are decided
before the module is written, not after.

## D16 — Agent discoverability is a V1 deliverable

`llms.txt`, `llms-full.txt`, an install skill, and an MCP server. In 2026 an agent
selecting the project is a distribution channel and the cost is near zero.

This is why `AGENTS.md`, `CLAUDE.md` and `.mcp.json` are tracked rather than ignored
(see `0002`).

## D18 — The repository is `impruthvi/saas-foundation`

No Packagist submission (D10), so the GitHub path is the only address. The name stays
vendor-neutral so that a later move between organizations is a rename plus a redirect.

## D19 — Templated from `shipfastlabs/modern-vue-starter-kit-auth`

Not `laravel new`, and not the official `laravel/vue-starter-kit` re-tooled by hand. That
kit is the official starter kit plus precisely D7's dependency floor, already assembled.
Upstream is MIT and its copyright is retained in `LICENSE.md`.

**Accepted cost:** inherited choices nobody here made get one deliberate audit pass and
are then kept with a reason or removed with one. That audit is `0002`.

## D20 — The tenant boundary is proven by a runtime query guard, not only by an architecture test

"No tenant-owned model is queried without an `organization_id` scope" is a property of
queries, and `arch()` reads classes. The static rules stay (a `TenantOwned` model must
use the trait; a model with an `organization_id` column must be `TenantOwned`), but the
load-bearing proof is a `DB::listen` guard installed for the whole test suite: any
select, update or delete against a tenant-owned table whose SQL carries no
`organization_id` predicate fails the test that caused it.

**Why:** every test in the suite becomes a boundary test, including the ones nobody
wrote with tenancy in mind. A guard that only inspects declarations cannot fail for the
case it exists to catch.

## D21 — `Project` is written at M1, as the first genuinely tenant-owned model

`Organization` is the tenant and `Membership` is a special case, so without it M1 would
validate its tenancy abstraction against nothing. `Project` arrives minimal (id,
organization, name) and is on the D8 journey already. M5 adds the limit, the usage meter
and the upgrade prompt; it does not introduce the model.

**Why:** an abstraction whose first consumer arrives four milestones later is a guess.

## D22 — `Membership` is tenant-owned, with exactly one audited way around the scope

The membership table carries `organization_id` and is scoped like every other
tenant-owned table. The one query that cannot be scoped — "which organizations does this
user belong to", which runs before any organization is known — lives in
`App\Tenancy\MembershipRepository` and is the only permitted `withoutTenantScope()`
caller in the product surface. A test asserts that.

**Why:** the alternative leaves the member list unscoped by default, which is the same
leak wearing a different hat. One named, tested door beats an unmarked one.

## D23 — Ownership is `organizations.owner_id`; a membership's role is rank, not permission

One writable fact for "who owns this organization". `MembershipRole` is `Admin` or
`Member` only — there is no `Owner` role — and ownership is derived from `owner_id`.
When `spatie/laravel-permission` arrives team-scoped at M3, it owns permissions
exclusively; `memberships.role` keeps meaning rank and never becomes a second permission
store.

**Why:** two stores for one fact drift the first time a transfer half-fails, and M4 reads
this to decide who is billed.

## D24 — Tenant context propagates through `Illuminate\Log\Context`, and absence forgets

The framework already dehydrates context into every queue payload and hydrates it on
`JobProcessing`, before the payload is unserialized. The application adds
`tenant.organization_id` on set, and restores `TenantContext` from it on hydration.

Two framework behaviours bind the implementation:

- `Context::hydrate()` runs on **every** job, including payloads carrying no context, so
  the listener must **forget** the tenant when the key is absent. Otherwise a long-lived
  worker carries one job's organization into the next.
- `Model::newQueryForRestoration()` uses `newQueryWithoutScopes()`, so `SerializesModels`
  restores tenant-owned models with the scope bypassed. A `retrieved` guard raises
  `CrossTenantAccess` when a row's organization disagrees with the resolved tenant.

Scheduled commands and webhook processing never rely on ambient state; they wrap work in
`runFor()`.

## D25 — Deleting a user who solely owns a shared organization is refused

Account deletion transfers or refuses; it never orphans. A personal organization is
deleted with its user. A non-personal organization with other members blocks the
deletion until ownership is transferred, and `organizations.owner_id` is
`restrictOnDelete` so the database is the backstop rather than the only line of defence.

**Why:** from M4 the organization holds a live subscription, and silent orphaning is
discovered by the person still being billed.

## D26 — Keys are auto-incrementing integers; the current organization lives in the session

Organizations are addressed by `slug` in forms and links, not in a URL prefix. There is
no `/o/{slug}` segment: the current organization is held in the session and changed
through an explicit switch endpoint.

**Why:** once M4 writes Cashier rows keyed to organizations, the key shape is a migration
against a payment provider, so it is decided before the first row exists. A URL segment
built on globally unique slugs is also enumerable, which leaks other tenants' names for
no benefit the session approach lacks.

## D27 — Resolving an invitation by token is the second query that cannot be scoped

It lives in `App\Tenancy\InvitationRepository`, which joins `MembershipRepository` on
the allowed-caller list in `tests/Unit/TenantScopingTest.php`. Like that one it runs
inside `runWithoutTenant()`, because both halves of the boundary have to stand down: the
global scope so the `SELECT` runs at all, and the `retrieved` guard so a row belonging to
another organization does not raise on arrival.

**Why:** the person holding an invitation token is outside the tenant by definition —
unauthenticated, or signed in with their own organization resolved. The question is asked
before the organization it concerns is known, which is exactly D22's situation a second
time. D22's wording says "the one query"; adding a second door quietly is precisely what
that test exists to notice, so it gets a number.

The repository returns the row and nothing else. Whether it may be taken is the accepting
action's job, because the answer is one of eight distinct refusals and a repository that
returned null would collapse them into "not found".

**Consequence for tests:** every test that resolves an invitation by token emits SQL with
no `organization_id`, so the suite-wide guard fails it. `throughTheAuditedDoor()` in
`tests/Pest.php` is the one sanctioned way past, and it is named so the escape is visible
in a diff.

## D28 — Route model binding runs after the tenant is resolved

`SubstituteBindings` is removed from its stock position in the web group and appended
after `ResolveTenantContext` in `bootstrap/app.php`. Two tests in
`tests/Feature/Tenancy/TenantBoundaryTest.php` lock the ordering.

**Why:** binding queries the model, and a tenant-owned model's global scope raises when no
organization is resolved (D3). In its shipped position, binding `{invitation}` — or
`{project}` at M5 — is a 500, and a cross-tenant request gets a server error instead of
the 404 it deserves. The alternative is never binding a tenant-owned model, which is a
permanent tax on every route the foundation will grow.

**Cost accepted:** the middleware order is now something this project owns rather than
inherits, so an upgrade that reshuffles the web group has to be read rather than merged.

## D29 — The permissions team is `organization_id`, and the invariant is asserted directly

`spatie/laravel-permission` runs team-scoped with the organization as the team.
`column_names.team_foreign_key` is `organization_id`, because this codebase has one word
for the tenant. `register_permission_check_method` is **`false`**: the package default
installs a `Gate::before` that answers every ability ahead of every policy, which is a
second authorization idiom and can short-circuit a policy's own rules. Policies stay the
only place an ability is answered, which means permissions are asked with
`hasPermissionTo()` and never with `can('some.permission')` — the latter is false for
everybody, administrators included.

The team is held on a registrar, not in `Illuminate\Log\Context`, so nothing carries it
into a queue payload. `App\Tenancy\TenantContext` is its one writer: `setId()` pushes it
and `forget()` nulls it. The second half is the one that matters. A registrar left
holding the previous organization answers `can()` for a tenant nobody resolved while
every query stays correctly scoped — an authorization leak with no query leak.

**D20's guard cannot see that, and cannot see this package at all.**
`TenantQueryGuard` returns early on any SQL containing the string `organization_id`,
before it checks which table was touched, so team-scoped queries pass it
unconditionally — including `organization_id is null` when no team is resolved. M3
therefore supplies its own invariant, asserted for every test in the suite:
`getPermissionsTeamId()` equals `TenantContext::id()`, null included. The assignment
tables are registered with the query guard as well, which is what makes the cross-team
detach on account deletion visible enough to need a named door.

**Cost accepted:** a published `config/permission.php` this project now owns, so a
package upgrade that reshapes it has to be read rather than merged. The same cost D28
accepted for the middleware order.

## D30 — Role definitions are global; only assignments are team-scoped

`roles` rows carry a null `organization_id` and are seeded once, by the migration that
creates the tables. The per-organization fact lives in `model_has_roles` and
`model_has_permissions`, which are never null and which cascade when an organization is
deleted — foreign keys the package's own stub omits, and without which a later
organization reusing an id inherits grants nobody gave it.

**Why:** per-team role rows would have `CreateOrganization` write a role catalog for
every organization that will ever exist, including every personal one, on a path whose
failure the registering user cannot act on. Global definitions isolate identically,
because isolation lives in the assignment.

**Two package behaviours this binds.** `Role::create()` fills the team key from whatever
team is resolved unless the key is passed explicitly, so every seed statement passes
`'organization_id' => null`. And the package's unique index is
`(organization_id, name, guard_name)`, which both Postgres and MySQL treat as
non-constraining when the first column is null — safe only because roles are created
once by a migration and never at runtime. A runtime role-creation path may not be added
without solving that first.

**Extension point (D15's requirement, decided now):** a customer-defined role is the same
row with an organization in that column. The swap is `config('permission.models.role')`
to a subclass — which will trip `tests/Unit/TenantScopingTest.php`, since a model in
`app/Models` carrying `organization_id` must be `TenantOwned` and a global role cannot
be. The subclass belongs outside `app/Models`, or that test gains a recorded exemption
at the same time.

## D31 — Rank is the writable fact; the role assignment is a projection of it

`memberships.role` is written; the `model_has_roles` row is derived from it in the same
transaction, by the same action, with `syncRoles` so a promotion replaces rather than
accumulates. `OrganizationRole::forRank()` is the only translation between the two, and
`AddOrganizationMember` is the only place a membership comes into being — which is what
makes it the only place an assignment does.

Three things this decision does **not** claim.

**Rank is not the only fact that gates access.** `memberships.status` is the other, and no
permission can express it. Every organization policy climbs the same ladder, extracted
into `App\Concerns\ChecksOrganizationPermissions` so it cannot be copied wrongly: no
organization resolved, then active membership, then the `owner_id` floor, then the
permission.

**`organizations.owner_id` remains the floor.** A single missing assignment would
otherwise lock an owner out of inviting, out of promoting anybody, and out of every
in-app path back. The floor sits _after_ the membership check, not before it: it exists
for a drifted assignment, not for a suspended membership, and suspending an owner is a
deliberate act that should hold.

**A projection is not self-proving.** Under `RefreshDatabase` every membership was
created by the code under test, so "they match" is vacuous. The assertions that earn
their keep are the backfill — migrating a database that already had memberships — and
the reverse direction, an assignment whose membership is gone.

**Consequences.** Removing a membership must revoke explicitly: `model_has_roles` is
keyed to organizations and users, never to memberships, so a delete on its own leaves the
person holding everything they had. That revocation is a `deleted` hook on `Membership`
rather than a line in one action. And ownership and rank must not disagree — the owner
can be neither removed nor demoted, and the last _active_ administrator can be neither,
because a suspended administrator holds the rank and grants nothing.

---

## Numbers recorded in milestone plans

From M4 on, each milestone's decisions are written in full in its plan, next to the
evidence and review that produced them. They bind exactly as the ones above do.

| Numbers | Milestone         | Recorded in                                                           |
| ------- | ----------------- | --------------------------------------------------------------------- |
| D32–D37 | M4, billing       | [`plans/0003-m4-billing.md`](../plans/0003-m4-billing.md)             |
| D38–D45 | M5, entitlements  | [`plans/0004-m5-entitlements.md`](../plans/0004-m5-entitlements.md)   |
| D46–D52 | M6, admin console | [`plans/0005-m6-admin-console.md`](../plans/0005-m6-admin-console.md) |

## Numbers not reproduced here

D13, D14 and D17 concern product sequencing, competitive positioning and the entitlements
package's internal domain model. They are recorded in the private planning repository.
D17's outcome is public as the released package
[`impruthvi/cashier-entitlements`](https://github.com/impruthvi/cashier-entitlements).
