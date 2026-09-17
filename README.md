# Laravel SaaS Foundation

The layer founders rebuild after authentication: organizations as the tenant and billing
boundary, tenant-scoped authorization, subscriptions, entitlements, usage, and an
operations console.

Built **above** Laravel's official starter kits, never duplicating them.

> **Status: pre-alpha, and honestly so.** The authentication layer below works and is
> tested. The foundation itself is being built now, in the open, milestone by milestone.
> The journey's first two segments run today. Nothing here is ready to depend on yet.
> There is no tagged release.

## What it will be

**MIT · Inertia + Vue · a real entitlement boundary · a billing path that is actually
tested.** Four claims, and no race to ship the largest feature checklist.

The definition of done is one journey, which is simultaneously the demo, the
specification, and an automated end-to-end test:

> Register → a personal organization is created → invite a teammate → subscribe to Pro
> via Stripe Checkout in test mode → create projects until the plan limit is hit and the
> upgrade prompt appears → open admin and inspect the resolved entitlement, the usage
> counter, and the webhook event that set it.

If that journey cannot be demonstrated, the project is not done, regardless of how much
code exists.

## Progress

| Milestone                                              | State       |
| ------------------------------------------------------ | ----------- |
| M0 — Skeleton, inherited-tooling audit, governance, CI | **Done**    |
| M1 — Organizations, memberships, tenant context        | **Done**    |
| M2 — Invitations                                       | Next        |
| M3 — Tenant-scoped RBAC                                | Not started |
| M4 — Cashier on the organization, Stripe Checkout      | Not started |
| M5 — Entitlements, usage, the plan limit               | Not started |
| M6 — Filament admin and operations                     | Not started |
| M7 — `saas:demo`, the journey as one test              | Not started |

A milestone is done when its segment of the journey runs, not when its code exists.

## What runs today

Registering creates exactly one personal organization, with the user as its owner and a
member of it, in a single transaction. There are no personal subscriptions, ever: a solo
user is an organization of one rather than a second billing subject threaded through the
product, and the workspace switcher is hidden for them rather than absent.

Every request resolves one organization, held in the session and changed through an
explicit switch that checks membership rather than trusting the request. That resolved
tenant reaches background work: a queued job resolves the organization it was dispatched
for, and the next job on the same worker inherits nothing.

**Scoping is enforced at the model boundary, not in controllers.** A tenant-owned model
queried with no organization resolved raises rather than quietly returning every
tenant's rows, writes are constrained the same way reads are, and a row arriving from
another organization raises even on the paths that bypass Eloquent's global scopes — the
one a queued job takes when it restores a serialized model.

That rule is asserted against queries rather than declarations: a listener installed for
the whole test suite fails any test whose SQL reaches a tenant-owned table without an
`organization_id` predicate, including tests written with no tenancy in mind. Reads that
are deliberately cross-tenant say so, and there is exactly one of them in the
application.

## The boundary this exists to fix

An entitlement is what an organization may do **right now**. It is the application's
answer, derived from billing facts, plan mapping and overrides — not a JSON column on a
row synced from a payment provider, and never a provider API call in the request path.

That resolution layer is already built and released as a standalone package:

- **[`impruthvi/cashier-entitlements`](https://github.com/impruthvi/cashier-entitlements)**
  — features, numeric limits, usage meters with idempotent increments, audited
  time-bound overrides, and reconciliation against the provider.
- **[`impruthvi/cashier-dunning`](https://github.com/impruthvi/cashier-dunning)** —
  replays recorded Stripe billing lifecycles offline, shuffled and duplicated, through
  the application's real webhook route. It enters here as a `require-dev` dependency and
  its job is to prove entitlements survive failed payments, retries, cancellation and
  out-of-order webhooks.

This foundation is their first consumer, not their replacement.

## Requirements

PHP 8.4+ · Laravel 13 · PostgreSQL (the documented path; SQLite works for local
development) · Node 22+

CI runs the full gate — Pint, Rector, Larastan, Pest and the frontend checks — against
both PostgreSQL and SQLite on every push.

## Installation

Once there is a release worth installing:

```bash
laravel new my-app --using=impruthvi/saas-foundation
```

The skeleton is distributed from GitHub and is deliberately **not** on Packagist — a
starter kit is a project template, not a dependency.

## Built on

Laravel 13 · Fortify · Inertia 3 · Vue · Wayfinder · Tailwind · shadcn-vue ·
Pest 5 · Larastan · Rector · Pint · Filament (admin only)

## Support

Issues are triaged weekly. Security reports are acknowledged within 7 days. There is no
response-time SLA, no LTS, and releases happen when they are ready. One maintainer —
the scope is kept to what one person can actually operate.

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
