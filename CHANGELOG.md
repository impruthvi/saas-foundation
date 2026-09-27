# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until a `1.0.0` release, minor versions may contain breaking changes; they will always
be listed here under **Changed** or **Removed**.

## [Unreleased]

### Added

- **The journey, runnable (M7).** `laravel new my-app --using=https://github.com/impruthvi/saas-foundation`
  installs and migrates the kit through the installer's post-create-project hook.
  `php artisan saas:demo` seeds the journey up to the Free plan's limit through the
  product's own actions, in one transaction, in `local` and `testing` only.
  `php artisan saas:stripe` finds or creates the Pro price in a Stripe test account and
  writes the keys and the webhook signing secret to `.env`, and `composer dev` starts
  `stripe listen` when the Stripe CLI is installed.
- **The journey as one browser test.** `tests/Committed/JourneyTest.php` walks from
  registration to the admin console's view of the Stripe event that set the plan. A CI
  job installs each commit through the Laravel installer and walks it again there.
- **Honest billing states.** A missing key, or a publishable key in the secret slot, reads
  as "Stripe is not configured" without calling Stripe; a missing price says so; the
  projects screen says a paid plan is being applied while its refresh is pending.
- **Agent discoverability.** `llms.txt`, a generated `llms-full.txt` that the gate keeps
  current, and a `saas-foundation` skill that Laravel Boost publishes to every agent.
- **Operations console (M6).** A removable Filament panel for operators: organization
  lookup, the entitlement inspector, the subscription timeline, webhook replay, an
  append-only audit log, and time-bound, reasoned impersonation. CI removes it with
  `scripts/remove-admin-console.php` and runs the whole gate without it.
- **Entitlements and usage (M5).** `impruthvi/cashier-entitlements` resolves what an
  organization may do from its billing facts, locally. The `projects` limit is enforced
  server-side, including under concurrent creates, with a Free-plan floor beneath the
  package answer and an upgrade prompt that only reflects it.
- **Billing on the organization (M4).** Laravel Cashier with the organization as the
  customer, a config-owned plan catalog, Stripe Checkout, and a webhook that resolves the
  organization before Cashier writes. Deliveries are recorded, and a late one never
  overwrites a newer one. `impruthvi/cashier-dunning` replays a recorded lifecycle,
  shuffled and duplicated, in the gate.
- **Tenant-scoped roles (M3).** `spatie/laravel-permission` with the organization as the
  team. A role granted in one organization grants nothing in another, asserted directly,
  over HTTP and across a real queue round trip. Members can be removed and have their rank
  changed, and neither the owner nor the last active administrator can be.
- **Invitations (M2).** Expiring, revocable, audited offers of membership, made to an
  email address rather than to a user because the recipient usually has no account yet.
  Accept, decline, revoke and resend, with a queued email carrying the only usable copy
  of the token. Expiry is derived from `expires_at` and never stored; tokens are sha-256
  digests behind a unique index.
- **Eight distinct refusals.** Expired, revoked, declined, already accepted, addressed to
  another, organization not accepting members, already invited, already a member — each
  its own class under one abstract `InvitationRefused`, so a caller catches once while the
  tests assert the reason. A token that resolves to nothing is a 404 worded honestly,
  never "expired".
- **A second audited way around the tenant scope.** `App\Tenancy\InvitationRepository`
  resolves an invitation by token for someone who is outside the tenant by definition.
  `tests/Unit/TenantScopingTest.php` still fails the build if a third one appears.
- **Organizations (M1).** The organization is the tenant: `Organization`, `Membership`
  and a first tenant-owned `Project`, with a personal organization created for every user
  at registration.
- **Tenant context.** `App\Tenancy\TenantContext` resolves one organization per unit of
  work and carries it into queued jobs and queued notifications through
  `Illuminate\Log\Context`. Scheduled commands and webhook processing state their
  organization with `runFor()` rather than inheriting one.
- **The boundary is proven against queries, not declarations.** A `DB::listen` guard
  fails any test whose SQL reaches a tenant-owned table without an `organization_id`
  predicate, backed by static rules for what a query cannot show: a model carrying the
  column without declaring the interface, and callers reaching for the escape hatch
  outside the places allowed it.
- **Ownership transfer**, and an account deletion that refuses to orphan an organization
  instead of silently leaving one behind.
- **The current organization** is resolved from the session per request and changed
  through `POST /organizations/{organization}/switch`. The workspace switcher is hidden
  for a solo user, not absent.
- Project skeleton, templated from `shipfastlabs/modern-vue-starter-kit-auth`: Laravel
  13, PHP 8.4, Fortify, Inertia 3 with Vue, Wayfinder, Tailwind, shadcn-vue, Pest 5,
  Larastan, Rector and Pint.
- Governance: MIT licence with upstream attribution retained, contributing guide,
  security policy, code of conduct, and this changelog.
- `tests/Unit/StrictnessTest.php`, pinning the model strictness the tenant boundary
  depends on.
- CI runs the full gate against both PostgreSQL and SQLite on every push, so neither
  supported database can drift unnoticed.
- `laravel/boost` as a dev dependency, with `AGENTS.md`, `CLAUDE.md` and `.mcp.json`
  tracked so a contributor's agent inherits this project's conventions.

### Changed

- The journey hits the Free plan's limit before subscribing. Pro is the top plan, so no
  upgrade prompt can appear at its limit.
- Code comments keep only the constraints the code cannot show; the reasoning lives in
  commit messages.
- Account deletion is refused while the user solely owns an organization other people are
  in, and takes the organizations they alone own with them when it proceeds. It also logs
  out with `logoutCurrentDevice()`: `Auth::logout()` cycles the remember token, which
  saves the model, and saving a model whose row has just been deleted re-inserts the
  account.
- Automatic relationship autoloading is disabled for the test run, so that
  `preventLazyLoading` can actually fire on models drawn from a collection.
- The inherited tree now passes its own formatter, so `composer ci:check` succeeds on a
  clean checkout.
- `.env.example` documents PostgreSQL as the supported alternative to the SQLite default,
  replacing a commented MySQL block that documented neither.

### Fixed

- **Route model binding on a tenant-owned model returned 500.** `SubstituteBindings` ran
  ahead of `ResolveTenantContext`, so binding queried the model before an organization
  was resolved and the global scope raised. It now runs after the tenant resolver, and a
  cross-tenant request gets the 404 it deserves.

### Removed

- The internal planning documents: `docs/`, `CONTEXT.md` and `TODOS.md`. The rules and the
  vocabulary now live in `AGENTS.md`, and open work is tracked as GitHub issues.
- The generated `_ide_helper.php` is no longer tracked. Regenerate it locally with
  `php artisan ide-helper:generate`.

[Unreleased]: https://github.com/impruthvi/saas-foundation/commits/main
