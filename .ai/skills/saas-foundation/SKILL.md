---
name: saas-foundation
description: 'Use when installing, demoing, or changing an application built on impruthvi/saas-foundation: organizations as the tenant and billing boundary, tenant-scoped RBAC, Cashier subscriptions on the organization, entitlements and the projects limit, or the removable Filament admin console. Covers the install command, saas:demo and saas:stripe, the tenancy traps that fail tests, and the fixed domain vocabulary.'
license: MIT
metadata:
    author: impruthvi
---

# SaaS Foundation

## Install and demo

```bash
laravel new my-app --using=https://github.com/impruthvi/saas-foundation
cd my-app
php artisan saas:demo                       # local only; prints two one-time passwords
php artisan saas:stripe sk_test_YOUR_KEY    # Pro price, keys and webhook secret into .env
composer dev                                # server, queue worker, Vite, stripe listen
```

Do not pass `--pest` to the installer: it rewrites `tests/Pest.php`. The GitHub URL form is
required; the `vendor/package` form looks the kit up on Packagist, where it is not
published. `saas:demo --fresh` rebuilds the database and seeds again.

## Rules that are enforced

- The organization is the tenant and the billing subject, never the user. A subscription
  belongs to an `Organization`; an architecture test forbids anything else.
- Tenant-owned models are scoped at the model boundary. A query on one with no organization
  resolved raises. Work with no ambient tenant (commands, webhooks, the admin console)
  wraps itself in `TenantContext::runFor($organization, ...)`.
- A suite-wide guard fails any test whose SQL touches a tenant-owned table without an
  `organization_id` predicate. Deliberate crossings say so: `throughTheAuditedDoor()` for
  invitation token lookups, `TenantQueryGuard::allowUnscoped()` for queued jobs restoring
  tenant-owned models, `whileClosingAnAccount()` for account deletion.
- `bootstrap/app.php` owns the web middleware order: route model binding runs after the
  tenant is resolved.
- Ask permissions with `hasPermissionTo()`, never `can('some.permission')`, which is false
  for everybody because the package's gate hook is off. `can()` with a policy ability is
  fine.
- A membership's rank (Admin or Member) is written; its RBAC role is derived from it and
  never written alone. Ownership is `organizations.owner_id`, not a rank.
- The `projects` limit is enforced server-side in `CreateProject`. A hidden button is not a
  limit.
- Business logic lives in `app/Actions`, one `handle()` per class. The admin console calls
  actions and read services only.

## Tests

`composer test` runs Unit and Feature. `composer test:committed` needs a file database and
Chromium: it holds the journey browser test, because an entitlement refresh refuses to run
inside a transaction. `composer test:concurrency` needs PostgreSQL.

## Removing the admin console

`php scripts/remove-admin-console.php` deletes the console, its operator table and
commands, and every section marked `@chisel-admin-console`. Mark any new console-only code
the same way.

## Vocabulary

Use the terms in `AGENTS.md` and never the words they displace: organization (not team or
tenant), membership (not seat), rank versus role, plan versus price, entitlement,
allowance, limit, usage meter, operator (not admin), admin console (not dashboard).
