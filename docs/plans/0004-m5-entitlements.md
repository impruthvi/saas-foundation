# 0004 — M5, entitlements and usage in the product

- **Date:** 2026-09-21
- **Status:** Proposed. Drafted and reviewed by `/plan-eng-review` including an outside
  voice; see the review report at the end. Ten findings were taken during that review and
  every one is reflected below — four of them reversed or corrected the first draft.
- **Branch:** `feat/m5-entitlements`, to be cut from `origin/main` after M4 merged.
- **Milestone:** M5 of `plans/0001-vertical-slice.md`.
- **Decisions it rests on:** D1, D3, D8, D11, D15, D20, D21, D24, D26, D28, D29, D31,
  D32, D33, D34, D35, D36, D37.
- **Decisions it proposes:** D38, D39, D40, D41, D42, D43, D44, D45.
- **Proof it is done:** an administrator on Free creates two projects and is refused the
  third **server-side**, with the upgrade prompt naming the plan that would allow more;
  subscribes to Pro through Checkout; the webhook lands; the refreshed entitlement allows
  the third. On PostgreSQL, two processes racing for one remaining project produce exactly
  one project and one refusal. Offline, the recorded dunning lifecycle replays shuffled and
  duplicated with identical subscription facts, and the state it leaves behind resolves to
  an identical allowance every pass.

## The one sentence

`impruthvi/cashier-entitlements` arrives with **`Organization` as the entitlement owner
under the morph alias `organization`**, the `projects` allowance read from the same
`config/billing.php` the billing screen already reads, a declared free-plan floor beneath
the package's answer, the limit enforced inside the same transaction that inserts the
project, and a webhook that refuses to apply an event older than the state it would
overwrite.

## What already exists and is not rebuilt

| Existing                                                            | What M5 does with it                                                                                                                                       |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `App\Models\Project` (M1, D21) — tenant-owned, has a factory        | Gains a controller, a policy, a request and two screens. The model itself is unchanged.                                                                    |
| `config/billing.php` — `plans.*.prices.*.allowances.projects`       | **Is** the entitlement catalog, and also the floor (D45). M5 adds no second source of allowances.                                                          |
| `App\Billing\PlanCatalog` — reads that config, exposes `features()` | The adapter input for both `PriceCatalog` and the floor. Not re-parsed.                                                                                    |
| `App\Concerns\ChecksOrganizationPermissions`                        | `ProjectPolicy` uses the same ladder — resolved tenant, active membership, owner floor, then permission. No new authorization idiom.                       |
| `App\Actions\RemoveOrganizationMember::wouldLeaveNobodyInCharge()`  | The house "two requests must not both read the same stale answer" pattern. M5 does **not** copy it — `admit()` supplies the serialization (D40).           |
| `App\Tenancy\TenantContext::runForId()`                             | The tenant resolver for background and console entitlement work (D41). No second resolver, no second middleware.                                           |
| `App\Http\Requests\Billing\CheckoutRequest` (D33)                   | The organization-naming pattern. Project creation adopts it (D40) — a create permanently spends an allowance that cannot be released (D44).                |
| `App\Models\FailedWebhookEvent` (D36)                               | Already retains raw payloads. D42's guard is validated against them rather than against synthesized fixtures.                                              |
| `App\Actions\DeleteUser` + `BillingMustBeResolved` (D34)            | Unchanged. Entitlement rows survive a deleted organization, and that is safe — see D44.                                                                    |
| `tests/Support/TenantQueryGuard`                                    | Extended with the package's owner-keyed tables, which it cannot discover — they key on `owner_id`, not `organization_id`. Necessary, not sufficient (D40). |
| `phpunit.xml` — `QUEUE_CONNECTION=sync`, SQLite `:memory:`          | Both become traps rather than conveniences. See "Test traps this suite will hit".                                                                          |

Nothing above is rewritten. Three existing files change behaviour:
`app/Http/Controllers/Billing/StripeWebhookController.php` (D42),
`app/Providers/AppServiceProvider.php` (morph map, catalog, floor, job middleware), and
`phpunit.xml` (a fourth test suite, D43).

## Decisions this milestone proposes

### D38 — The organization is the entitlement owner, under the morph alias `organization`, and the catalog is the billing config

`Relation::morphMap(['organization' => Organization::class])`. This is not cosmetic and
not optional. `OwnerLocator::modelFor()` resolves the owner type through
`Relation::getMorphedModel($alias)` and requires the result to be exactly
`Cashier::$customerModel`:

```php
// src/Reconciliation/OwnerLocator.php:72
$class = Relation::getMorphedModel($alias);
if (! class_exists(Cashier::class) || $class === null || $class !== Cashier::$customerModel || ...)
```

Without the map, `OwnerLocator::reference()` stamps the owner type as the FQCN
`App\Models\Organization`, `getMorphedModel()` returns `null` for it, and every resolve,
refresh, sweep and `entitlements:reconcile --owner-type=organization` fails with
`owner_context_mismatch`. **Nothing in the package works until the map is registered.**

**Why this is safe against what is already stored:** `Relation::morphMap()` is a partial
map. `getMorphClass()` returns the alias only for listed classes, so
`model_has_roles.model_type` keeps storing `App\Models\User` exactly as D15 left it. A
test asserts both halves. `Relation::enforceMorphMap()` is deliberately **not** called,
because it would require mapping every polymorphic model the template ships.

**The catalog is `config/billing.php`.** D35 already made the plan and price catalog
configuration with no database projection, and `allowances` already sits on each price.
`PriceCatalog` is bound in `register()` from `PlanCatalog`, so there is exactly one place
a maintainer edits an allowance.

**The catalog version is derived, not typed.** A hand-maintained version string is a
comment that lies the first time someone forgets it, so the version is
`'v1-'.substr(hash('sha256', serialize($normalizedCatalog)), 0, 12)` — editing an allowance
changes the version by construction. A test asserts that changing one allowance changes the
version and that reordering the config array does not.

**Cost accepted:** a config edit invalidates every stored observation, so resolution falls
through to the floor (D45) until a refresh lands. That is the package's designed behaviour,
it is the safe direction, and `entitlements:doctor` reports it.

### D45 — The free plan is a declared floor beneath the package's answer, not a subscription

**The package cannot express a free tier.** It derives allowances from mapped
subscriptions, and it returns an empty map for an owner with no stored state, a mismatched
catalog version, an expired projection or a stale paid observation:

```php
// src/Resolution/LocalResolver.php:67
if ($state === null || $state['projection'] === [] || $state['catalog_version'] !== $this->catalog->version
    || $state['projection']['status'] !== 'allowed' || $state['observed_at'] > $at->getTimestamp()) {
    return [];
}
```

`OwnerAccess::limit()` then turns the absent key into **zero**, not into a default:

```php
// src/Resolution/OwnerAccess.php:42
$value = array_key_exists($feature, $values) ? $values[$feature] : 0;
```

A newly registered organization has never been refreshed and holds no Stripe subscription,
so it resolves `limit('projects') === 0` and cannot create its first project. The first
draft of this plan asserted a free allowance of two and was simply wrong.

**The decision: `App\Entitlements\ResolveAllowance` reads the package first and falls back
to the free plan's allowance in `config/billing.php` when the package yields no entry for
the feature.** One class, one method, the single reader for every call site — the
controller, the policy, the screen and `CreateProject` all go through it, so the floor
cannot be applied in one place and forgotten in another.

**Why a floor and not a $0 Stripe subscription for every organization.** Subscribing every
organization at registration would make the package's model honest end to end, and it is
the option a larger team should take. It also puts a Stripe network call inside the
registration path that D1 deliberately kept local, and makes M1's personal-organization
creation depend on a provider being reachable. The floor keeps registration offline.

**Stated plainly, because it is the cost:** one allowance answer is now computed outside
the package. An organization whose paid entitlement is denied, expired or stale therefore
resolves to the **free** allowance rather than to nothing — which is the correct product
behaviour and also means the package can never express a true zero through this reader. A
plan declaring `'projects' => 0` would be silently raised to the free allowance. No such
plan exists; a test asserts the floor is the free plan's value and fails if a zero-allowance
plan is ever added.

### D39 — `projects` is a stock, so its meter never resets: `cashier-entitlements` gains a `lifetime` rule and ships 0.2.0 first

`MeterPeriods` accepts three rules and `period()` throws `missing_meter_period` for a
numeric feature that declares none:

```php
// src/Usage/MeterPeriods.php:31
if (! in_array($rule, ['calendar_day', 'calendar_month'], true)) {
    throw new ReadFailure('missing_meter_period');
}
```

All three reset. `projects` does not. "10 projects on Pro" counts what an organization
**has**, not what it **consumed this month**. Declaring `calendar_month` would ship two
defects into the reference implementation of this project's own thesis: an organization
that deletes a project still cannot create one, and every rollover refills the allowance.

**The fix is upstream, because the primitive is missing upstream.** `cashier-entitlements`
0.2.0 adds `'lifetime'`: one unbounded `UsagePeriod` so the counter accumulates and never
rolls.

**The release is two changes, not one.** The service provider validates meter rules
independently of `MeterPeriods` and rejects anything it does not know:

```php
// src/CashierEntitlementsServiceProvider.php:203
if (! is_string($feature) || ! is_string($rule)
    || (! in_array($rule, ['calendar_day', 'calendar_month'], true) && ! str_starts_with($rule, 'billing:'))
```

Changing only `MeterPeriods` leaves the application booting into `invalid_meter_rules`.
Both sites change, and the package gains a test that boots with `lifetime` configured.

**The equivalence this rests on, stated so it can be checked:** a lifetime creation counter
equals a stock count **only while every creation is metered and no project is deleted**.
D44 forbids deletion for exactly this reason. Projects created before M5 — the factory rows
in tests, and any row in a repository upgraded past this milestone — are unmetered, so the
migration backfills one counter row per organization from `count(projects)`, and a test
asserts an organization with pre-existing projects resolves the correct remaining value.
Without that backfill the counter reads zero while the rows exist, and the limit is a lie
in the generous direction.

**The outside voice argued the reverse** — that a future inspector should not dictate the
product primitive, and that counting rows under a lock while reading the package's `limit()`
enforces the entitlement just as honestly. That argument is recorded here rather than
buried: it is correct that this is downstream reporting influencing an upstream model. The
decision stands because a limit enforced beside the entitlement system, in the project whose
thesis is that the entitlement boundary is real, demonstrates the opposite of the thesis.
If the backfill or the deletion constraint proves unworkable in implementation, reverse to
the local count — it is a contained change behind `ResolveAllowance` and `CreateProject`.

**Cost accepted:** M5 is blocked on a release of a second repository, and this application
pins `^0.2.0`.

### D40 — The limit is enforced inside the transaction that creates the project, and the request names the organization it spends

`CreateProject` calls `NativeUsage::admit()` and inserts the project inside the supplied
callback. `admit()` runs inside `NativeStateStore::synchronized()`, which serializes per
owner, and it evaluates the limit **after** taking that lock:

```php
// src/Usage/NativeUsage.php:59
$limit = $resolver->for($owner, $at)->limit($feature);
if ($limit !== null && ($current > $limit || $quantity > $limit - $current)) {
    throw new LimitExceeded($feature);
}
```

So two concurrent creates against one remaining allowance cannot both read the same stale
total. `LimitExceeded` becomes a 422 carrying the resolved limit, the usage and the cheapest
plan whose allowance exceeds it.

**`admit()` is owner isolation, not tenant authorization.** It accepts whatever
`OwnerReference` it is handed and never consults `TenantContext`. Because the insert also
bypasses Eloquent, `BelongsToOrganization`'s `creating`, `saving` and `retrieved` guards do
not fire either. **Both safety nets are out of the path at once.** `CreateProject`
therefore compares the owner reference against `TenantContext::idOrFail()` and raises
`CrossTenantAccess` before calling `admit()`, and the boundary test asserts that refusal
directly rather than relying on the query guard — which cannot see these tables anyway,
since they key on `owner_id`.

**The request names the organization, the way a billing mutation does (D33).** The first
draft classified project creation as an ordinary tenant mutation that could ride the session
tenant. D44 makes a create permanently spend an allowance that cannot be released, which
puts it in the same category as a charge: a form rendered for organization A and submitted
after the session moved to B spends B's allowance irreversibly. `StoreProjectRequest`
carries the organization slug and returns 409 on a mismatch, reusing `CheckoutRequest`'s
shape and copy.

**Idempotency is narrower than it looks.** The package fingerprints
`[quantity, occurredAt, operation]` — **not the project name**:

```php
// src/Usage/NativeUsage.php:40
$payload = hash('sha256', json_encode([$quantity, $occurredAt?->format('U.u'), $resolver === null ? 'record' : 'admit'], ...));
```

Reusing a key with a different name returns the original receipt and creates nothing; it
does not raise `IdempotencyConflict`. And the package stores no receipt→project
association, so a retried create cannot return the row the first attempt made. M5 therefore
stores `usage_receipt_id` on `projects` (unique, nullable for backfilled rows) and, on a
duplicate receipt, looks the original project up and returns it. The key is derived
server-side from the request's idempotency token, and a test asserts that the same token
with a different name returns the first project rather than creating a second.

**Two more consequences the implementation carries rather than discovers:**

1. The insert supplies `organization_id`, `created_at` and `updated_at` itself, because no
   model event fills them.
2. `limit()` returns `null` for unlimited and `0` for none, and D45's floor means `0` is
   unreachable through `ResolveAllowance`. The screen still renders the two cases
   differently, because the floor is a decision that can be revisited and the renderer
   should not encode its absence.

**The upgrade prompt is a read of the same resolver**, rendered from `limit`, `usage` and
`remaining`. It never carries its own threshold. A UI that hides the button is not a limit;
the button may be hidden, and the endpoint still refuses.

### D41 — Every entitlement read of a tenant-scoped relation resolves the tenant first, in the queue and on the console

`RefreshManager::refresh()` reads the owner's subscriptions through the Eloquent relation:

```php
// src/Reconciliation/RefreshManager.php:73
foreach ($relation->limit(10001)->pluck('stripe_id') as $id) {
```

`App\Models\Subscription` carries `BelongsToOrganization`, whose `TenantScope` raises
rather than falling back:

```php
// app/Tenancy/TenantScope.php:33
if (! $tenant->hasTenant()) {
    throw TenantContextMissing::forModel($model::class);
}
```

**There are two entry points, not one.** `RefreshOwner` is dispatched onto a worker
carrying only an `OwnerReference`. And `entitlements:reconcile --apply` never goes near the
job — it calls the manager in-process:

```php
// src/Commands/ReconcileCommand.php:76
$reference = $manager->request($owner, dispatch: false);
$result = $manager->refresh($reference);
```

with the dry-run path reading the same relations through `DryRunReconciler`. A job
middleware alone leaves every console invocation broken.

**The mechanism:** one queued job middleware for `RefreshOwner`, plus a console-command
wrapper that resolves the tenant around `entitlements:reconcile` and
`entitlements:sweep`/`recover`. Both read the integer key off the owner reference and call
`TenantContext::runForId()`. The package stays unpatched.

**This is not a third audited door.** The organization is recovered from the owner reference
the work already carries, exactly as D32's webhook recovers it from the Stripe customer.
Nothing reads across tenants. D22's and D27's list of audited doors stays at two.

**Failure direction:** without the wrapper, refreshes fail loudly with
`TenantContextMissing` and `doctor` reports the backlog. They do not silently resolve the
wrong organization.

### D42 — A Stripe event older than the state it would overwrite is ignored, and the watermark outlives the row

`subscriptions` gains a nullable `stripe_event_at`. A watermark table keyed by the **Stripe
subscription id** carries the same value independently, because the subscription row may not
exist when the guard needs it.

**Why the watermark cannot live only on the row.** Cashier's deletion handler updates an
existing subscription and writes nothing when there is none. So `deleted` arriving before
`created` leaves no row, no watermark, and the later `created` — genuinely older — applies
and resurrects a subscription Stripe already ended. Storing the watermark beside the row
rather than on it closes that case.

**Scope of the rule:** subscription-shaped events only
(`customer.subscription.created|updated|deleted`). Customer and invoice events write
different rows and have no stored counterpart. The comparison and the write happen in one
statement under the tenant already resolved by D32, so two concurrent handlers cannot both
read the same watermark.

**Two holes left open deliberately, because closing them costs more than they cost:**
equal timestamps apply rather than skip, so two events Stripe emitted in the same second
remain arrival-order dependent — dropping the second is the unrecoverable direction. And an
event with a missing or unparseable `created` applies, for the same reason. Both are
recorded here so that a later convergence bug is diagnosed rather than rediscovered.

**Why M5 and not M4.** `TODOS.md` blocks this on M5 explicitly. At M4 an out-of-order
`active` after a `deleted` was a wrong label on a screen. At M5 it is a **wrong access
decision**.

**Cost accepted:** one more Cashier handler overridden, deepening the D32 upgrade-reading
cost in the place `TODOS.md` already flags as highest.

### D43 — A stale observation falls to the floor, the sweep refreshes before expiry, and concurrency is proven on PostgreSQL

`config/cashier-entitlements.php` ships `'freshness' => []` and the package refuses to
guess. M5 configures `['max_stale_age' => 3600]`, so a paid allowance whose observation ages
past an hour stops resolving and D45's floor answers instead — the organization drops to the
free allowance rather than to nothing.

**Why deny rather than `retain_last_known`.** Retaining keeps paid access alive indefinitely
through a provider outage. That is kinder to one customer and wrong as a kit default: a
subscription that ended during an outage would keep its allowance until a human noticed.
An hour bounds the wrong answer.

**The sweep needs its own threshold.** `SweepManager` falls back to `maxStaleAgeSeconds`
when `stale_after` is null, which means it would only request a refresh once state has
**already** expired. `schedule.stale_after` is set to 1800 — half the expiry — so refreshes
are requested while the answer is still good, and cron frequency is not asked to compensate
for a threshold it cannot move.

**Concurrency is proven on PostgreSQL, in its own suite.** `phpunit.xml:29-30` runs SQLite
`:memory:`, where every connection opens a private database, so competing connections are
impossible; and `tests/Feature/Billing/CheckoutConcurrencyTest.php` — named in the first
draft as the harness to copy — is a **sequential retry test** that spawns nothing. A fourth
`Concurrency` suite runs against PostgreSQL with separate connections and controlled
overlap, as its own CI job, mirroring what the entitlements package does in
`scripts/test-postgres.sh`. This is the harness `TODOS.md` has wanted since the browser-test
entry, and M5 is the milestone whose definition of done requires it.

### D44 — A project cannot be deleted at M5, and a deleted organization's entitlement rows are inert

A stock meter has no decrement: `NativeUsage::record()` requires `quantity >= 1` and the
package exposes no release path. Shipping deletion without one would mean a customer who
deletes a project has spent the allowance permanently — worse than not shipping deletion.
Deletion, and the receipt-referencing release operation it needs upstream, is one `TODOS.md`
entry.

**Deleting an organization leaves its counters and receipts behind**, because the package
never prunes — a deliberate published position, since retention is what keeps deduplication
correct. Those rows are **inert rather than dangerous**: `organizations.id` is
auto-incrementing (D26) and PostgreSQL never reuses a sequence value, so no future
organization can inherit them. They are a storage and data-retention question, not a
correctness one, and they belong with the `failed_webhook_events` retention entry that M6
already owns. D34 is unchanged; no new refusal is added to `DeleteUser`.

## Architecture

### Where the entitlement owner comes from, per entry point

```
                                  ┌─────────────────────────────────┐
  HTTP (web)                      │  Organization resolved by       │
  POST /projects ─────────────────▶  ResolveTenantContext (session) │
                                  │  D26 · middleware order D28     │
                                  └───────────────┬─────────────────┘
                                                  │ + slug in the request body (D40/D33)
                                                  │   mismatch ──▶ 409
                                                  ▼
                                        OwnerLocator::reference()
                                                  │  morph alias 'organization' (D38)
                                                  ▼
                                          OwnerReference
                                                  │  compared against TenantContext (D40)
                   ┌──────────────────────────────┼──────────────────────────────┐
                   ▼                              ▼                              ▼
        ResolveAllowance (D45)          NativeUsage::admit()            RefreshManager::request()
        package first, free floor       (write, serialized)             (durable, commits then dispatches)
        beneath it                                │                              │
                                                  ▼                              ▼
                                        projects INSERT                   RefreshOwner (queue)
                                        + usage_receipt_id                       │
                                        same txn, same conn                      ▼
                                                              ┌──────────────────────────────────┐
  Queue (worker)                                              │  TenantContext::runForId()       │
  RefreshOwner ───────────────────────────────────────────────▶  job middleware        (D41)    │
                                                              └──────────────────┬───────────────┘
  Console                                                                        │
  entitlements:reconcile --apply ──▶ RefreshManager::refresh() ──────────────────┤
  entitlements:sweep / recover   ──▶ (never touches RefreshOwner)                │
                                     command wrapper, same runForId     (D41)    │
                                                                                 ▼
                                                                    $organization->subscriptions()
                                                                    (tenant-scoped — needs the above)

  Stripe (webhook)
  POST /stripe/webhook ──▶ customerIdFor() ──▶ Cashier::findBillable() ──▶ TenantContext::runFor()
                                                          │                      (D32, unchanged)
                                                          ▼
                                    watermark compare, keyed by stripe subscription id (D42)
                                                  older ──▶ skip ──▶ 200, recorded
                                                  equal / newer / unparseable ──▶ apply
                                                          │
                                                          ▼
                                             Cashier handlers ──▶ WebhookHandled
                                                          │  after commit — never inside a transaction
                                                          ▼
                                             RefreshManager::request()
```

### The create path, branch by branch

```
POST /projects  (auth, verified, tenant resolved)
  │
  ├─ ProjectPolicy::create ──────────── denies ──▶ 403
  │    resolved tenant · active membership · owner floor · hasPermissionTo (D29 — never can('...'))
  │
  ├─ StoreProjectRequest ────────────── invalid ──▶ 422 (name required, max:255)
  │                              └───── organization slug mismatch ──▶ 409 (D40/D33)
  │
  ▼
CreateProject::handle(Organization, string $name, string $idempotencyToken)
  │
  ├─ OwnerLocator::reference($organization) ── ReadFailure ──▶ 503 + log (misconfiguration)
  ├─ reference->key !== TenantContext::idOrFail() ──▶ CrossTenantAccess  (D40 — admit will NOT catch this)
  │
  ▼
NativeUsage::admit(owner, 'projects', 1, key, resolver, callback)
  │
  ├─ synchronized(): per-owner lock ──── contended ──▶ blocks, then re-reads (the concurrency proof)
  │
  ├─ receipt already exists ──▶ look up projects.usage_receipt_id ──▶ return the ORIGINAL project
  │     (the name is NOT in the fingerprint — a different name does not conflict, it returns the first)
  │
  ├─ ResolveAllowance limit (D45: package, then free floor)
  │    ├─ null (unlimited) ──────────────────────▶ admit
  │    └─ n, usage >= n ─────────────────────────▶ LimitExceeded ──▶ 422 + upgrade prompt
  │
  ├─ UnknownFeature / FeatureTypeMismatch ───────▶ 503 + log (catalog drift, not user error)
  │
  ▼
callback: $db->table('projects')->insert([organization_id, name, usage_receipt_id, timestamps])
  │  no Eloquent — BelongsToOrganization does NOT fire (D40)
  ▼
commit ──▶ 303 redirect to projects.index
```

### Subscription states the projects screen must render

| Resolved state                     | `ResolveAllowance` for `projects` | Screen                                               |
| ---------------------------------- | --------------------------------- | ---------------------------------------------------- |
| Brand new, never refreshed         | free floor (2) — D45              | Counter `n / 2`; prompt at the boundary naming Pro   |
| No subscription                    | free floor (2)                    | Same                                                 |
| Active paid                        | paid allowance (10)               | Counter `n / 10`; no prompt below the boundary       |
| On grace period (`ends_at` future) | paid allowance                    | Paid allowance resolves; a banner names the end date |
| Ended                              | free floor (2)                    | Counter `n / 2`, `n` possibly above it — see below   |
| Observation stale > 1h (D43)       | free floor (2)                    | Same as ended, plus `doctor` reports the staleness   |
| Catalog version mismatch           | free floor (2)                    | Same, until a refresh lands                          |

**Over the limit after a downgrade is a real state, not an error.** An organization with six
projects that lapses to Free keeps its six projects and can create none. The screen says so
plainly. Nothing deletes a project to make an allowance true.

## Scope

### In scope

1. `cashier-entitlements` 0.2.0 upstream: `'lifetime'` in **both** `MeterPeriods` and the service provider's validator, plus a boot test (D39).
2. `composer require impruthvi/cashier-entitlements:^0.2.0`; publish config and migrations.
3. `Relation::morphMap` + `PriceCatalog` bound from `PlanCatalog` with a content-hashed version (D38).
4. `App\Entitlements\ResolveAllowance` — package first, free-plan floor beneath, single reader (D45).
5. `config/cashier-entitlements.php`: `enabled`, `freshness.max_stale_age = 3600`, `meters.projects = 'lifetime'`, `schedule.owner_type = 'organization'`, `schedule.stale_after = 1800`, sweep and recover cron (D43).
6. `App\Actions\CreateProject` — tenant comparison, `admit()`, receipt→project association (D40).
7. `projects.usage_receipt_id` migration + backfill of one counter row per organization from `count(projects)` (D39/D40).
8. `ProjectController` (index, store), `StoreProjectRequest` with the organization slug, `ProjectPolicy`, routes.
9. `App\Enums\Permission::ManageProjects` + catalog migration + role grant, following D29/D31.
10. `resources/js/pages/projects/Index.vue` and the upgrade prompt, fed by `ResolveAllowance`.
11. Queued job middleware **and** console-command wrapper resolving the tenant (D41).
12. Watermark table + event-age guard in `StripeWebhookController` (D42).
13. `RefreshManager::request()` on `WebhookHandled`, dispatched after commit.
14. A PostgreSQL `Concurrency` test suite in `phpunit.xml` and its own CI job (D43).
15. Tests, per the coverage diagram below.

### NOT in scope

- **Project deletion and allowance release** — a stock meter has no decrement (D44). `TODOS.md`.
- **Pruning entitlement rows for deleted organizations** — inert under auto-incrementing ids (D44); belongs with M6's retention work. `TODOS.md`.
- **Counting pending invitations against seats** — needs a commercial policy call that belongs with billing. M5 supplies the enforcement pattern it should reuse, and nothing more.
- **Generalizing D33's organization-naming to every mutation** — M5 extends it to one more endpoint on its merits (D40); the sweep across M1–M3's controllers still reopens D26. `TODOS.md`.
- **A $0 Stripe subscription per organization** — the alternative to D45's floor, recorded there with its tradeoff.
- **Closing D42's equal-timestamp and missing-timestamp holes** — recorded in D42 with the reason.
- **`subscription_items.organization_id`** — no consumer until M6. `TODOS.md`.
- **`failed_webhook_events` retention** — M6 owns the replay surface. `TODOS.md`.
- **Overrides** (`overrides => false`) — one query per resolve for a feature with no reader until M6.
- **Masterix and Pennant adapters** — D11's filter.
- **Reporting usage to Stripe metered billing** — the package states plainly it does not.
- **A second metered feature** — one numeric feature proves the boundary; a second proves it twice.

## Tests

### Coverage diagram

```
CODE PATHS                                              USER FLOWS
[+] App\Entitlements\ResolveAllowance  (D45)            [+] First project on a new account
  ├── package answers ──────── [★★★ planned] Pt13         └── [★★★ planned] never refreshed → allowed — Pt13
  ├── package empty → floor ── [★★★ planned] Pt13
  ├── stale paid → floor ───── [★★★ planned] Pt8        [+] Create within allowance
  ├── version mismatch → floor  [★★  planned] Pt6         ├── [★★★ planned] counter increments — Pt1
  └── zero-allowance plan ───── [★★  planned] Pt13        └── [★★★ planned] prompt absent below limit — Pt7

[+] App\Actions\CreateProject                           [+] Refused at the limit
  ├── admit() happy ────────── [★★★ planned] Pt1          ├── [★★★ planned] 422 + named plan — Pt2
  ├── LimitExceeded ────────── [★★★ planned] Pt2          ├── [→E2E] button hidden, endpoint refuses — Pb1
  ├── receipt reuse → original  [★★★ planned] Pt3         └── [★★  planned] over-limit after downgrade — Pt7
  ├── same key, new name ───── [★★★ planned] Pt3
  ├── cross-tenant owner ───── [★★★ planned] Pt9        [+] Upgrade and retry
  ├── slug mismatch → 409 ──── [★★★ planned] Pt10         ├── [→E2E] refused → checkout → allowed — Pb1
  ├── limit null (unlimited) ─ [★★  planned] Pt2          └── [★★  planned] refresh applies allowance — Pt5
  ├── UnknownFeature ───────── [★★  planned] Pt6
  └── insert carries org ───── [★★★ planned] Pt1        [+] Operational
                                                          ├── [★★  planned] doctor reports stale — Pt8
[+] Concurrency (PostgreSQL suite, D43)                   ├── [★★  planned] sweep fires before expiry — Pt8
  └── 2 processes, 1 remaining [★★★ planned] Pt4          └── [★★  planned] refresh backlog recovers — Pt5

[+] D38 catalog + morph map                             [+] Boundary
  ├── organization → alias ─── [★★★ planned] Pt6          ├── [★★★ planned] A's usage invisible to B — Pt9
  ├── User → FQCN unchanged ── [★★★ planned] Pt6          └── [★★★ planned] create refuses other tenant — Pt9
  ├── version changes on edit ─ [★★  planned] Pt6
  └── version stable on reorder [★★  planned] Pt6       [+] Authorization
                                                          ├── [★★★ planned] no permission → 403 — Pt10
[+] D41 tenant for background + console                   └── [★★★ planned] non-member → 403 — Pt10
  ├── queued job resolved ──── [★★★ planned] Pt5
  ├── reconcile --apply ────── [★★★ planned] Pt5        [+] Migration
  ├── reconcile dry-run ────── [★★  planned] Pt5          └── [★★★ planned] backfill from count(projects) — Pt14
  └── absent wrapper raises ── [★★  planned] Pt5

[+] D42 event-age guard
  ├── older skipped ────────── [★★★ planned] Pt11
  ├── newer applied ────────── [★★★ planned] Pt11
  ├── equal applied ────────── [★★  planned] Pt11
  ├── missing created applies ─ [★★  planned] Pt11
  ├── delete-before-create ─── [★★★ planned] Pt11   ← the watermark-outlives-the-row case
  └── replay facts converge ── [★★★ planned] Pt12
  └── post-replay resolve ──── [★★★ planned] Pt12b  ← outside the replay transaction

COVERAGE: 0/40 today — 40/40 planned (2 E2E, 1 PostgreSQL-only)
```

### Test files

| File                                                              | Proves                                                                     |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `tests/Feature/Projects/CreateProjectTest.php` (Pt1, Pt2, Pt3)    | Happy path, `limit()` shapes, receipt reuse, same-key-new-name             |
| `tests/Concurrency/ProjectLimitConcurrencyTest.php` (Pt4)         | **PostgreSQL only.** Two processes, one remaining, one project             |
| `tests/Feature/Entitlements/RefreshOwnerTest.php` (Pt5)           | D41: queue, `reconcile --apply`, dry-run, absent wrapper raises            |
| `tests/Feature/Entitlements/CatalogTest.php` (Pt6)                | D38: morph map both halves, derived version both ways                      |
| `tests/Feature/Projects/ProjectScreenTest.php` (Pt7)              | Counter, prompt presence, over-limit-after-downgrade                       |
| `tests/Feature/Entitlements/FreshnessTest.php` (Pt8)              | D43: stale falls to the floor; sweep threshold precedes expiry             |
| `tests/Feature/Entitlements/EntitlementBoundaryTest.php` (Pt9)    | A's usage invisible to B; `CreateProject` refuses another tenant           |
| `tests/Feature/Projects/ProjectAuthorizationTest.php` (Pt10)      | Policy ladder (`hasPermissionTo`, never `can('...')`), 409 on slug         |
| `tests/Feature/Billing/WebhookEventAgeTest.php` (Pt11)            | D42 including delete-before-create                                         |
| `tests/Feature/Billing/DunningReplayConvergenceTest.php` (Pt12)   | Shuffled + duplicated replay, identical **subscription facts**             |
| `tests/Feature/Entitlements/PostReplayResolutionTest.php` (Pt12b) | The state the replay leaves resolves identically — outside the transaction |
| `tests/Feature/Entitlements/AllowanceFloorTest.php` (Pt13)        | D45: every path that yields `[]`, and the zero-allowance guard             |
| `tests/Feature/Entitlements/UsageBackfillTest.php` (Pt14)         | Pre-existing projects produce the correct remaining value                  |
| `tests/Browser/ProjectLimitTest.php` (Pb1)                        | Refused → checkout → allowed, in a real browser                            |

### Test traps this suite will hit

1. **The replay opens a transaction around everything.** `ReplayRunner.php:82` calls `beginTransaction()` and then asserts on `callbacksDiscardedByRollback()`. An after-commit refresh request made during replay is discarded, and forcing it inline hits `refresh_inside_transaction`. This is why Pt12 asserts subscription facts and Pt12b resolves separately — the first draft's single combined test was not implementable.
2. **`QUEUE_CONNECTION=sync` plus `refresh_inside_transaction`.** Any `request()` made inside an open transaction dispatches inline and trips it. Requests are dispatched after commit, and Pt5 asserts exactly that.
3. **`admit()` opens a transaction**, so nothing inside the create callback may request a refresh.
4. **SQLite `:memory:` gives every connection its own database**, so concurrency cannot be tested in the default suite at all. Pt4 lives in the PostgreSQL suite and is skipped elsewhere with an explicit message, never silently.
5. **`TenantQueryGuard` cannot see the package's tables** — they key on `owner_id`. Registering them is necessary and not sufficient; Pt9 asserts the boundary directly.
6. **`RefreshOwner` carries an `OwnerReference`, not a model**, so it does not trip D24's `SerializesModels` trap. The tenant still has to be resolved — a different problem with the same symptom.
7. **`ShouldBeStrict` is on in every environment** (`config/essentials.php:165`) while `tests/Pest.php:33` disables automatic eager loading and `config/essentials.php:47` enables it in production. Any relation the screen or the projector reads as a property raises in one and not the other; the screen passes scalars from `ResolveAllowance` and never a model with unloaded relations.
8. **Adding a permission migration breaks a rollback helper.** The RBAC catalog replay must name its migration with `--path`, not `--step 1`, or appending M5's migrations silently rolls back the wrong file.
9. **The dunning replay must be worth something.** M4's replay registered no resolver; Pt12b is the milestone's headline test precisely because it closes that.

## Failure modes

| Codepath                  | Realistic production failure                          | Test | Error handling   | User sees                                           |
| ------------------------- | ----------------------------------------------------- | ---- | ---------------- | --------------------------------------------------- |
| `ResolveAllowance`        | Package returns `[]` on a brand-new organization      | Pt13 | Yes (floor)      | The free allowance, not a refusal                   |
| `ResolveAllowance`        | A zero-allowance plan is added and silently raised    | Pt13 | Yes (test fails) | Nothing — the build breaks first                    |
| `CreateProject`           | `LimitExceeded` at the boundary                       | Pt2  | Yes              | 422 + upgrade prompt naming the plan                |
| `CreateProject`           | Two tabs, double submit                               | Pt3  | Yes              | One project; the retry returns the original         |
| `CreateProject`           | Stale tab, session moved to another organization      | Pt10 | Yes              | 409 naming the change                               |
| `CreateProject`           | Deadlock retry re-runs the callback                   | Pt4  | Package (3×)     | Nothing; the insert is transactional                |
| `OwnerLocator::reference` | Morph map missing after a provider edit               | Pt6  | Yes              | 503 + logged `owner_context_mismatch`               |
| `RefreshOwner` / console  | Wrapper absent → `TenantContextMissing`               | Pt5  | Yes (loud)       | Nothing immediately; `doctor` reports the backlog   |
| Refresh                   | Stripe unreachable → observation ages past an hour    | Pt8  | Yes              | Free allowance + upgrade prompt while paying        |
| Sweep                     | Threshold equals expiry → refresh only after the fact | Pt8  | Yes              | Bounded by `stale_after` at half the expiry         |
| Webhook                   | `active` delivered after `deleted`                    | Pt11 | Yes              | Nothing; the stale event is skipped and recorded    |
| Webhook                   | `deleted` delivered before `created`                  | Pt11 | Yes              | Watermark survives the missing row; no resurrection |
| Webhook                   | Two events in the same second, conflicting            | —    | **No**           | Arrival order wins — accepted, recorded in D42      |
| Projects screen           | Six projects, lapsed to Free                          | Pt7  | Yes              | Counter `6 / 2`, create refused, nothing deleted    |
| Migration                 | Pre-existing projects unmetered                       | Pt14 | Yes (backfill)   | Correct remaining value from the first request      |

**One accepted gap, not a critical one:** conflicting same-second events have no test and no
handling, and the outcome is silent. It is accepted in D42 with its reason, and the
alternative — dropping the second event — loses data irrecoverably. Everything else above
has a test, handling, and a visible consequence.

## Parallelization

| Step                                                                            | Modules touched                                                                 | Depends on                |
| ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------- |
| S1 — package 0.2.0: `lifetime` in `MeterPeriods` **and** the provider validator | (separate repo)                                                                 | —                         |
| S2 — morph map, catalog binding, `ResolveAllowance`, config                     | `app/Providers/`, `app/Entitlements/`, `config/`                                | S1                        |
| S3 — create path, request, policy, routes, receipt column, backfill             | `app/Actions/`, `app/Http/`, `app/Policies/`, `routes/`, `database/migrations/` | S2                        |
| S4 — projects UI                                                                | `resources/js/pages/projects/`                                                  | S3                        |
| S5 — tenant wrapper for queue **and** console                                   | `app/Jobs/`, `app/Console/`, `app/Providers/`                                   | S2                        |
| S6 — watermark table + event-age guard                                          | `app/Http/Controllers/Billing/`, `database/migrations/`                         | —                         |
| S7 — permission + catalog migration                                             | `app/Enums/`, `database/migrations/`                                            | —                         |
| S8 — PostgreSQL `Concurrency` suite + CI job                                    | `phpunit.xml`, `.github/workflows/`, `tests/Concurrency/`                       | — (harness), S3 (subject) |

```
Lane A: S1 → S2 → S3 → S4        (sequential — each needs the previous shape)
Lane B: S6                       (independent; controller + its own migration)
Lane C: S7                       (independent; enum + migration)
Lane D: S8 harness               (independent; CI and phpunit config only)
Lane E: S5                       (starts after S2)
```

Launch **B**, **C** and **D** immediately alongside **A**. **E** joins once S2 lands; S8's
test body joins once S3 lands.

**Conflict flags:** S2 and S5 both register in `app/Providers/AppServiceProvider.php` — keep
them in one lane if the merge proves noisy. S3, S6 and S7 all add migrations; the filenames
differ, but S3's backfill must run after S7 if the permission migration is ever reordered,
and the RBAC catalog replay helper must name its file with `--path` (trap 8).

## Implementation tasks

- [ ] **T1 (P1, human: ~1d / CC: ~40min)** — `cashier-entitlements` — add `lifetime` to `MeterPeriods` **and** the provider validator; release 0.2.0
    - Surfaced by: Outside voice #9 — `CashierEntitlementsServiceProvider.php:203` rejects unknown rules independently of `MeterPeriods.php:31`
    - Files: `src/Usage/MeterPeriods.php`, `src/CashierEntitlementsServiceProvider.php`, tests, `CHANGELOG.md`
    - Verify: `composer release-gate`
- [ ] **T2 (P1, human: ~3h / CC: ~15min)** — entitlements — `ResolveAllowance` with the free-plan floor, as the single reader
    - Surfaced by: Outside voice #1 — `LocalResolver.php:67` returns `[]` and `OwnerAccess.php:42` turns that into `0`
    - Files: `app/Entitlements/ResolveAllowance.php`, `app/Providers/AppServiceProvider.php`
    - Verify: `vendor/bin/pest tests/Feature/Entitlements/AllowanceFloorTest.php`
- [ ] **T3 (P1, human: ~2h / CC: ~10min)** — providers — morph map + `PriceCatalog` from `PlanCatalog` with a content-hashed version
    - Surfaced by: Architecture — `OwnerLocator.php:72` requires a registered alias
    - Files: `app/Providers/AppServiceProvider.php`, `config/cashier-entitlements.php`
    - Verify: `vendor/bin/pest tests/Feature/Entitlements/CatalogTest.php`
- [ ] **T4 (P1, human: ~1.5d / CC: ~45min)** — actions/http — `CreateProject` with the tenant comparison, `admit()`, receipt→project association, slug-naming request
    - Surfaced by: Architecture — `admit()` never consults `TenantContext`; Code quality — the fingerprint omits the project name (`NativeUsage.php:40`)
    - Files: `app/Actions/CreateProject.php`, `app/Http/Controllers/Projects/`, `app/Http/Requests/Projects/StoreProjectRequest.php`, `app/Policies/ProjectPolicy.php`, `routes/web.php`, `database/migrations/*_add_usage_receipt_id_to_projects.php`
    - Verify: `vendor/bin/pest tests/Feature/Projects`
- [ ] **T5 (P1, human: ~4h / CC: ~20min)** — jobs/console — tenant wrapper for `RefreshOwner` **and** the entitlements commands
    - Surfaced by: Outside voice #8 — `ReconcileCommand.php:76` calls `refresh()` in-process, bypassing the job
    - Files: `app/Jobs/ResolveTenantForRefresh.php`, `app/Console/`, `app/Providers/AppServiceProvider.php`
    - Verify: `vendor/bin/pest tests/Feature/Entitlements/RefreshOwnerTest.php`
- [ ] **T6 (P1, human: ~1d / CC: ~30min)** — billing — watermark table keyed by Stripe subscription id + event-age guard
    - Surfaced by: Outside voice #3 — Cashier's deletion handler writes nothing when no row exists, so a row-local watermark cannot survive delete-before-create
    - Files: `database/migrations/*_create_subscription_event_watermarks_table.php`, `app/Http/Controllers/Billing/StripeWebhookController.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing/WebhookEventAgeTest.php`
- [ ] **T7 (P1, human: ~1d / CC: ~30min)** — tests/ci — PostgreSQL `Concurrency` suite and its CI job
    - Surfaced by: Test review — `CheckoutConcurrencyTest.php` is a sequential retry test and `phpunit.xml:29-30` makes competing connections impossible
    - Files: `phpunit.xml`, `.github/workflows/`, `tests/Concurrency/ProjectLimitConcurrencyTest.php`
    - Verify: `vendor/bin/pest --testsuite=Concurrency` against PostgreSQL
- [ ] **T8 (P1, human: ~4h / CC: ~20min)** — migrations — backfill one counter row per organization from `count(projects)`
    - Surfaced by: Outside voice #10 — a lifetime counter equals stock only if every existing row is metered
    - Files: `database/migrations/*_backfill_project_usage_counters.php`
    - Verify: `vendor/bin/pest tests/Feature/Entitlements/UsageBackfillTest.php`
- [ ] **T9 (P2, human: ~1d / CC: ~30min)** — tests — split the replay claim: facts converge in-replay, resolution asserted after
    - Surfaced by: Test review — `ReplayRunner.php:82` wraps the replay in a transaction and discards after-commit callbacks
    - Files: `tests/Feature/Billing/DunningReplayConvergenceTest.php`, `tests/Feature/Entitlements/PostReplayResolutionTest.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing tests/Feature/Entitlements`
- [ ] **T10 (P2, human: ~3h / CC: ~15min)** — enums/migrations — `ManageProjects` permission, catalog row, role grant; RBAC replay helper uses `--path`
    - Surfaced by: Prior learning `migration-replay-helper-breaks-when-migrations-are-appended` (9/10)
    - Files: `app/Enums/Permission.php`, `database/migrations/*_add_manage_projects_permission.php`, `tests/Feature/Authorization/RolePersistenceTest.php`
    - Verify: `vendor/bin/pest tests/Feature/Authorization`
- [ ] **T11 (P2, human: ~1d / CC: ~30min)** — frontend — projects index, create form, upgrade prompt from `ResolveAllowance` scalars
    - Surfaced by: D40 — the prompt is a projection, never its own threshold; prior learning `wayfinder-actions-are-gitignored` (9/10)
    - Files: `resources/js/pages/projects/Index.vue`, upgrade prompt component
    - Verify: `php artisan wayfinder:generate && vendor/bin/pest tests/Feature/Projects/ProjectScreenTest.php tests/Browser/ProjectLimitTest.php`
- [ ] **T12 (P2, human: ~2h / CC: ~10min)** — tests — register the package's owner-keyed tables with `TenantQueryGuard`, assert the boundary directly
    - Surfaced by: Architecture — the guard keys on `organization_id` and cannot discover `owner_id` tables
    - Files: `tests/Support/TenantQueryGuard.php`, `tests/Feature/Entitlements/EntitlementBoundaryTest.php`
    - Verify: `vendor/bin/pest tests/Feature/Entitlements`

## Inline diagrams the implementation should carry

- `app/Actions/CreateProject.php` — the branch diagram above, because the transaction boundary, the Eloquent bypass and the fact that `admit()` does not check the tenant are all invisible from the call site.
- `app/Entitlements/ResolveAllowance.php` — the seven resolved states from the screen table, because "the package returned nothing" and "the package said zero" are indistinguishable at the call site and mean opposite things.
- `app/Jobs/ResolveTenantForRefresh.php` — why it exists: package work → tenant-scoped relation → raise. A future reader will otherwise delete it as ceremony.
- `app/Http/Controllers/Billing/StripeWebhookController.php` — extend the existing comment block with the watermark comparison and the two directions it deliberately fails in.
- `app/Models/Subscription.php` — the docblock already explains the tenant scope; note why the watermark lives beside the row rather than on it.

## GSTACK REVIEW REPORT

| Review        | Trigger               | Why                             | Runs | Status       | Findings                           |
| ------------- | --------------------- | ------------------------------- | ---- | ------------ | ---------------------------------- |
| CEO Review    | `/plan-ceo-review`    | Scope & strategy                | 0    | —            | —                                  |
| Codex Review  | `/codex review`       | Independent 2nd opinion         | 1    | issues_found | 10 findings, 10 folded             |
| Eng Review    | `/plan-eng-review`    | Architecture & tests (required) | 1    | issues_open  | 10 issues, 1 accepted critical gap |
| Design Review | `/plan-design-review` | UI/UX gaps                      | 0    | —            | —                                  |
| DX Review     | `/plan-devex-review`  | Developer experience gaps       | 0    | —            | —                                  |

- **CODEX:** Ten findings, nine verified against source by the reviewing model before being accepted. Four reversed the first draft: the package cannot express a free tier (D45 added), the dunning replay cannot host an entitlement assertion (tests split), the named concurrency harness does not exist (PostgreSQL suite added), and the upstream release was under-scoped (provider validator added to T1). Two corrected it: `admit()` is not a tenant guard, and usage idempotency omits the domain payload.
- **CROSS-MODEL:** Both reviewers agree on D38, D40's transaction shape, D41's scope, and D42's necessity. They disagree on D39: the eng review keeps the lifetime meter so the entitlement system remains the single enforcement path; the outside voice argues a locked `count(projects)` with the package supplying only the allowance number is simpler and that a future inspector should not dictate the product primitive. The user chose to keep D39. The counter-argument and the reversal path are recorded inside D39 rather than discarded.
- **VERDICT:** ENG REVIEWED — 10 findings folded, 0 unresolved decisions, 1 deliberately accepted critical gap (conflicting same-second Stripe events remain arrival-order dependent, recorded in D42 with its reason). Not logged CLEAR because that gap is silent by construction; it is accepted, not fixed. Ready to implement.

NO UNRESOLVED DECISIONS
