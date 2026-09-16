# 0001 — Vertical slice build plan

- **Date:** 2026-09-16
- **Status:** **M0 complete.** `main` is pushed and both CI matrix jobs pass on GitHub
  Actions. **M1 in progress**, amended 2026-09-16 by D20-D26.
- **Decisions:** `docs/decisions/0001-architecture-decisions.md` and
  `docs/decisions/0002-inherited-tooling-audit.md`.
- **Defines done for:** D8, the ten-minute journey.
- **Repository:** this one.

## The journey this plan builds

D8 fixes one artifact that is simultaneously the README demo, the V1
definition-of-done, and an automated end-to-end test:

> Register → personal organization auto-created → invite a teammate → subscribe to Pro
> via Stripe Checkout in test mode → create projects until the plan limit is hit and the
> upgrade prompt appears → open admin and inspect the resolved entitlement, the usage
> counter, and the webhook event that set it.

Every milestone below is one segment of that sentence. **A milestone is not done when its
code exists; it is done when its segment of the journey runs.** If a capability does not
strengthen the journey or validate a core boundary, D11's scope filter applies: document
the extension point, defer the implementation.

## What already exists and is not rebuilt

- **Authentication** — inherited from the template (D19): Fortify, registration, login,
  email verification, password reset, confirmation, two-factor challenge, passkeys,
  profile and security settings, all with passing feature tests. The foundation is the
  layer _above_ this and does not touch it.
- **Entitlements, usage, overrides, reconciliation** — `impruthvi/cashier-entitlements`
  v0.1.0, already released. The application consumes it; it is not re-implemented here.
  Public surface: `LocalResolver::for(OwnerReference $owner): OwnerAccess`, then
  `can()` / `limit()` / `usage()` / `remaining()`, plus the
  `entitlements:doctor` / `reconcile` / `recover` / `sweep` commands.
- **Billing-edge-case proof** — `impruthvi/cashier-dunning` v0.2.1, entering as
  `require-dev` per D2.

## Milestones

| #      | Deliverable                                                                                      | Depends on | Proof it is done                                                                                                                                                                 |
| ------ | ------------------------------------------------------------------------------------------------ | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **M0** | Skeleton configured; inherited-tooling audit; Postgres; D9 governance; CI                        | —          | **Done** — full gate green on PostgreSQL and SQLite, locally and on GitHub Actions. 46 tests, 184 assertions. Audit recorded in `docs/decisions/0002-inherited-tooling-audit.md` |
| **M1** | Organizations, memberships, personal org at registration, tenant context                         | M0         | Registering creates exactly one personal organization; a job resolves the correct organization across a real queue roundtrip, and the next job on that worker inherits nothing   |
| **M2** | Invitations — expiring, revocable, audited                                                       | M1         | Expiry, revoke-then-accept, accept-as-wrong-user and re-invite all rejected with distinct errors                                                                                 |
| **M3** | Tenant-scoped RBAC on `spatie/laravel-permission`                                                | M1         | A role granted in organization A grants nothing in organization B                                                                                                                |
| **M4** | Cashier on `Organization`; plan/price catalog; Stripe Checkout, test mode                        | M1, M3     | Checkout completes in test mode, the webhook lands, and billing facts appear locally                                                                                             |
| **M5** | Entitlements wired; `projects` limit; the upgrade prompt                                         | M4         | Creating past the limit is refused **server-side**, and concurrent creates cannot exceed it                                                                                      |
| **M6** | Filament admin: entitlement inspector, usage counter, webhook timeline, audit log, impersonation | M5         | Deleting the admin module leaves the suite green with no dead navigation                                                                                                         |
| **M7** | `saas:demo`, the journey as one browser test, README, agent discoverability                      | M6         | `laravel new --using=impruthvi/saas-foundation` → `saas:demo` → journey completes, unassisted, inside ten minutes                                                                |

## Milestone detail

### M0 — Skeleton, audit, governance

Configuration is done. The audit is the substance.

**Inherited-tooling audit (D19's accepted cost).** **Done** — all six decisions are
recorded in `docs/decisions/0002-inherited-tooling-audit.md`. Two required code changes
(automatic relationship autoloading was disarming the lazy-loading guard; the 29,796-line
generated IDE helper is no longer tracked), three are kept as they arrived, and
`laravel/chisel` was promoted from unexamined to load-bearing — it is the mechanism M6's
removable admin already depended on. The audit also turned up a defect that was not on
the list: `composer ci:check` failed on a clean checkout because nine inherited files did
not satisfy their own formatter.

**PostgreSQL primary (D3).** The template ships SQLite. Switch the documented path to
Postgres, keep SQLite working for contributors, and add the rule that core migrations
avoid Postgres-only types so MySQL stays free where it is free.

**Governance, day one (D9).** `LICENSE.md` — done, dual copyright, upstream retained.
Still to write: `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, `CHANGELOG.md`,
semver. The published support promise is D12's exact wording and is not revised upward
while this project has one maintainer. Deferred until a first external contributor
exists: public RFC process, deprecation policy, release cadence.

**CI.** The template's workflow, extended to run against Postgres.

### M1 — Organizations

The module D6 committed to writing rather than inheriting from Jetstream.

- `Organization` — the tenant, owner of data and of the subscription.
- `Membership` — user ↔ organization, carrying role and status. Never a "seat"; a seat is
  a billed quantity, not a person.
- **Personal organization auto-created at registration (D1).** No personal
  subscriptions, ever. The workspace switcher is hidden for a solo user, not absent.
- **Lifecycle states** — the status columns and `MembershipStatus` land here because M2's
  invitations need them. The `Suspend` / `Archive` / `Restore` transitions have no caller
  until M6 and are deferred there under D11's filter; the extension point is the enum.
- **Ownership transfer**, named in the brief and load-bearing immediately: it is the
  remedy offered when account deletion is refused (D25).
- **`Project`**, minimal, as the first genuinely tenant-owned model (D21). Without it the
  tenancy machinery has no consumer until M5.
- **Tenant context resolver** (RFC 0001 Q6) reaching queued jobs, scheduled commands,
  notifications and webhook processing. This is the hard part and the reason Jetstream
  was rejected.

**Scoping is enforced at the model/repository boundary (D3), not in controllers.** The
architecture test asserting that no tenant-owned model is queried without an
`organization_id` scope is written before the models — and because that is a property of
queries rather than of classes, the static rules are backed by a suite-wide `DB::listen`
guard (D20). Two framework behaviours the resolver has to survive are recorded in D24:
context hydration fires on every job including empty ones, and `SerializesModels`
restoration bypasses global scopes.

**Vocabulary is fixed by `CONTEXT.md` and is not renegotiated in code review.**

### M2 — Invitations

Expiring, auditable, revocable. Accept, decline, revoke, resend. Email delivery.
Budgeted honestly as real, unglamorous work in D6.

### M3 — RBAC

`spatie/laravel-permission`, team-scoped with the organization as the team (D15). Expect
to extend it — display names on roles and permissions are the usual first need — so the
extension points are decided here, not improvised later.

### M4 — Billing

**`Subscription` belongs to `Organization`, never polymorphically to either (D1).** A
`SubscriptionController` that queries `where(['user_id' => ...])` is the exact failure
this decision exists to prevent; an architecture test forbids it.

Plan and price catalog config-first with the database as the projection. Stripe
Checkout in test mode. Webhook route wired to Cashier. `cashier-dunning` lands in
`require-dev` here and earns its D2 justification by replaying a recorded lifecycle,
shuffled and duplicated, asserting the resolved entitlement is identical every time.

### M5 — Entitlements and usage in the product

Wire `impruthvi/cashier-entitlements`. `Organization` becomes the `OwnerReference`. The
`projects` feature is numeric; hitting its limit raises the upgrade prompt.

**The limit is enforced server-side.** A UI that hides the button is not a limit. The
concurrency test — parallel creates against a limit of one remaining — is the one that
matters, and it is written before the feature.

This milestone is where the project's thesis becomes visible to a stranger: an
entitlement boundary that is real, rather than a JSON column on a provider-synced row.

### M6 — Admin console

Filament (D4), the stated exception to the one-frontend rule, bounded by a hard rule:
**no Livewire in the product surface, no Inertia in admin, and Filament resources call
application services only — zero business rules.** Removability is the acceptance test,
not an aspiration.

Surfaces: customer lookup · subscription timeline · webhook replay · entitlement
inspector · audited impersonation · audit log. These are the "operations included"
differentiator; three of them are the last clause of the D8 sentence.

### M7 — The journey as one artifact

`php artisan saas:demo` lands a developer mid-journey with **no manual database edits**.
The same journey is one Pest browser test. The README shows it.

D16 agent discoverability ships here, not as an afterthought: `llms.txt`,
`llms-full.txt`, an install skill, an MCP server. In 2026 an agent choosing the kit is a
distribution channel and the cost is near zero.

## Risks

- **Two frontend runtimes in one application.** D4 verified Filament 5.8.1 against
  `illuminate/contracts ^13.0`, but Livewire 4 and Inertia 3 coexisting is proven at M6,
  not assumed. If it fights, the admin module is the thing that moves, never the product
  surface.
- **Tenant context in background work.** The most likely source of a silent
  cross-tenant leak. It is M1, early and deliberately, with the architecture test first.
- **Scope creep through the admin console.** Filament makes building screens cheap,
  which is exactly how a one-maintainer V1 (D12) acquires a second maintainer's workload.
  D11's filter applies hardest here.
- **The journey is measured, not asserted.** "Ten minutes" is a claim in the README. M7
  times it on a clean machine and the README says whatever the clock said.
