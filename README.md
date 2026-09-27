# Laravel SaaS Foundation

The layer founders rebuild after authentication: organizations as the tenant and billing
boundary, tenant-scoped authorization, subscriptions, entitlements, usage, and an
operations console.

Built **above** Laravel's official starter kits, never duplicating them.

> **Status: pre-alpha.** Every milestone below runs and is tested, but there is no tagged
> release yet, and nothing here is ready to depend on in production.

## The ten-minute journey

**MIT · Inertia + Vue · a real entitlement boundary · a billing path that is actually
tested.** The definition of done is one journey, which is simultaneously this demo, the
specification, and an automated browser test (`tests/Committed/JourneyTest.php`):

> Register → a personal organization is created → invite a teammate → create projects
> until the Free plan's limit refuses one and the upgrade prompt appears → subscribe to
> Pro through Stripe Checkout in test mode → create the project that was refused → open
> the admin console and inspect the resolved entitlement, the usage counter, and the
> Stripe event that set it.

Timed on a clean machine: _not measured yet_.

## Try it

You need PHP 8.4+, Composer, Bun 1.3+ and Node 22+, a free
[Stripe](https://dashboard.stripe.com/register) account in test mode, and the
[Stripe CLI](https://docs.stripe.com/stripe-cli) for webhooks on your machine.

```bash
laravel new my-app --using=https://github.com/impruthvi/saas-foundation --bun
cd my-app
php artisan saas:demo
php artisan saas:stripe sk_test_YOUR_KEY
composer dev
```

- **`laravel new … --using=<GitHub URL>`** downloads the kit, creates a SQLite database,
  runs the migrations and builds the frontend. Use the URL form: the kit is deliberately
  not on Packagist, so `--using=impruthvi/saas-foundation` will not find it.
- **`saas:demo`** seeds the journey up to the Free plan's limit: Ada owns an organization
  with two projects, Grace has joined it, and Ada can open the admin console. It prints
  both passwords once. It runs only in `local` or `testing`, and `--fresh` rebuilds the
  database and seeds again.
- **`saas:stripe`** takes a test secret key, finds or creates the Pro price in your Stripe
  account, and writes the key, the price and the webhook signing secret to `.env`.
- **`composer dev`** starts the server, the queue worker, Vite and `stripe listen`, which
  forwards Stripe's webhooks to the app.

Then sign in as Ada, open `/projects`, and follow the upgrade prompt. Pay with Stripe's
test card `4242 4242 4242 4242`, any future date and any CVC.

### If the plan does not change after paying

- **"Your plan change is being applied."** stays on the projects screen: the queue worker
  is not running. Start the app with `composer dev`, not `php artisan serve` alone.
- **Nothing changes at all:** the webhook did not arrive. Check that `stripe listen` is
  running in `composer dev`, and rerun `php artisan saas:stripe` if you switched Stripe
  accounts, so the signing secret matches.
- **"Stripe is not configured"** on the billing screen: the secret key is missing, or a
  publishable key (`pk_test_…`) was pasted where the secret key (`sk_test_…`) belongs.

## What it gives you

**The organization is the tenant and the billing subject, never the user.** A solo user
is an organization of one, created at registration, rather than a second billing subject
threaded through the product. Invitations are expiring, revocable and audited.

**Scoping is enforced at the model boundary, not in controllers.** A tenant-owned model
queried with no organization resolved raises rather than returning every tenant's rows,
and the test suite fails any query that reaches a tenant-owned table without an
`organization_id` predicate, including in tests written with no tenancy in mind.

**Roles are tenant-scoped.** A role granted in one organization grants nothing in
another, asserted directly, over HTTP and across a real queue round trip.

**Subscriptions belong to the organization**, through Laravel Cashier and Stripe
Checkout. A webhook that arrives late never overwrites a newer one, and every delivery is
recorded.

**An entitlement is what an organization may do right now**, resolved locally from
billing facts and never by a provider call in the request path. The `projects` limit is
enforced server-side; the upgrade prompt only reflects it.

**An operations console**, built with Filament: organization lookup, the entitlement
inspector, the subscription timeline, webhook replay, an audit log and time-bound,
audited impersonation. It is removable: `php scripts/remove-admin-console.php` takes it
out, and CI runs the whole suite without it on every push.

## Progress

| Milestone                                              | State    |
| ------------------------------------------------------ | -------- |
| M0 — Skeleton, inherited-tooling audit, governance, CI | **Done** |
| M1 — Organizations, memberships, tenant context        | **Done** |
| M2 — Invitations                                       | **Done** |
| M3 — Tenant-scoped RBAC                                | **Done** |
| M4 — Cashier on the organization, Stripe Checkout      | **Done** |
| M5 — Entitlements, usage, the plan limit               | **Done** |
| M6 — Filament admin and operations                     | **Done** |
| M7 — `saas:demo`, the journey as one test              | Next     |

A milestone is done when its segment of the journey runs, not when its code exists.

## The packages underneath

- **[`impruthvi/cashier-entitlements`](https://github.com/impruthvi/cashier-entitlements)**
  — features, numeric limits, usage meters with idempotent increments, audited
  time-bound overrides, and reconciliation against the provider.
- **[`impruthvi/cashier-dunning`](https://github.com/impruthvi/cashier-dunning)** —
  replays recorded Stripe billing lifecycles offline, shuffled and duplicated, through the
  application's real webhook route. It proves entitlements survive failed payments,
  retries, cancellation and out-of-order webhooks.

This foundation is their first consumer, not their replacement.

## Working on it

`AGENTS.md` holds the rules, the test traps and the fixed vocabulary, for people and for
coding agents. [`llms.txt`](llms.txt) points agents at the rest, and Laravel Boost
publishes a `saas-foundation` skill to every agent it is configured for.

`composer test` runs the unit and feature suites. `composer ci:check` is the whole gate:
Pint, Rector, Larastan, the frontend checks, the billing replay, the browser journey and
the concurrency suite. CI runs it against PostgreSQL and SQLite on every push, installs
the kit through the Laravel installer, and runs it again with the admin console removed.

## Built on

Laravel 13 · Fortify · Inertia 3 · Vue · Wayfinder · Tailwind · shadcn-vue · Cashier ·
Pest 5 · Larastan · Rector · Pint · Filament (admin only)

## Support

Issues are triaged weekly. Security reports are acknowledged within 7 days. There is no
response-time SLA, no LTS, and releases happen when they are ready. One maintainer — the
scope is kept to what one person can actually operate.

See [SECURITY.md](SECURITY.md) to report a vulnerability, and
[CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

MIT. See [LICENSE.md](LICENSE.md).

Templated from
[`shipfastlabs/modern-vue-starter-kit-auth`](https://github.com/shipfastlabs/modern-vue-starter-kit-auth)
by Pushpak Chhajed, whose copyright is retained in the licence. That kit is the official
[`laravel/vue-starter-kit`](https://github.com/laravel/vue-starter-kit) with a modern
tooling setup, and it is the reason this project did not spend its first day wiring
Pest, Rector, Larastan and lint configuration.
