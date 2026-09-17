# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until a `1.0.0` release, minor versions may contain breaking changes; they will always
be listed here under **Changed** or **Removed**.

## [Unreleased]

### Added

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
- **A second audited way around the tenant scope (D27).** `App\Tenancy\InvitationRepository`
  resolves an invitation by token for someone who is outside the tenant by definition.
  `tests/Unit/TenantScopingTest.php` still fails the build if a third one appears.

### Fixed

- **Route model binding on a tenant-owned model returned 500 (D28).** `SubstituteBindings`
  ran ahead of `ResolveTenantContext`, so binding queried the model before an organization
  was resolved and the global scope raised. It now runs after the tenant resolver, and a
  cross-tenant request gets the 404 it deserves.

- **Organizations (M1).** The organization is the tenant: `Organization`,
  `Membership` and a first tenant-owned `Project`, with a personal organization
  created for every user at registration (D1, D21).
- **Tenant context.** `App\Tenancy\TenantContext` resolves one organization per
  unit of work and carries it into queued jobs and queued notifications through
  `Illuminate\Log\Context`. Scheduled commands and webhook processing state their
  organization with `runFor()` rather than inheriting one (D24).
- **The boundary is proven against queries, not declarations.** A `DB::listen`
  guard fails any test whose SQL reaches a tenant-owned table without an
  `organization_id` predicate, backed by static rules for what a query cannot
  show: a model carrying the column without declaring the interface, and callers
  reaching for the escape hatch outside the one place allowed it (D20, D22).
- **Ownership transfer**, and an account deletion that refuses to orphan an
  organization instead of silently leaving one behind (D23, D25).
- **The current organization** is resolved from the session per request and
  changed through `POST /organizations/{organization}/switch`. The workspace
  switcher is hidden for a solo user, not absent (D26).

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
  tracked so a contributor's agent inherits this project's conventions (D16).

### Changed

- Account deletion is refused while the user solely owns an organization other
  people are in, and takes the organizations they alone own with them when it
  proceeds. It also logs out with `logoutCurrentDevice()`: `Auth::logout()`
  cycles the remember token, which saves the model, and saving a model whose row
  has just been deleted re-inserts the account.
- Automatic relationship autoloading is disabled for the test run, so that
  `preventLazyLoading` can actually fire on models drawn from a collection.
- The inherited tree now passes its own formatter, so `composer ci:check` succeeds on a
  clean checkout.
- `.env.example` documents PostgreSQL as the supported alternative to the SQLite default,
  replacing a commented MySQL block that documented neither.

### Removed

- The generated `_ide_helper.php` is no longer tracked. Regenerate it locally with
  `php artisan ide-helper:generate`.

[Unreleased]: https://github.com/impruthvi/saas-foundation/commits/main
