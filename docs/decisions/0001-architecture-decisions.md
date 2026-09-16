# 0001 — Architecture decisions

- **Date:** 2026-09-08, amended 2026-09-16
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

---

## Numbers not reproduced here

D13, D14 and D17 concern product sequencing, competitive positioning and the entitlements
package's internal domain model. They are recorded in the private planning repository.
D17's outcome is public as the released package
[`impruthvi/cashier-entitlements`](https://github.com/impruthvi/cashier-entitlements).
