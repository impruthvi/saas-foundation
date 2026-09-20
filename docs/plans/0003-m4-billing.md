# 0003 — M4, billing on the organization

- **Date:** 2026-09-20
- **Status:** Proposed. Reviewed by `/plan-eng-review` including an outside voice; see the
  review report at the end. Twenty decisions were taken during that review and every one
  is reflected below.
- **Branch:** `feat/m4-billing`, to be cut from `origin/main` at `9f1312e`.
- **Milestone:** M4 of `plans/0001-vertical-slice.md`.
- **Decisions it rests on:** D1, D2, D3, D5, D8, D11, D12, D19, D20, D21, D22, D23, D25,
  D26, D27, D28, D29.
- **Decisions it proposes:** D32, D33, D34, D35, D36, D37.
- **Proof it is done:** an administrator subscribes an organization to Pro through Stripe
  Checkout in test mode, the webhook lands, and the billing screen shows the plan, the
  status and the period — then cancels, sees the grace period, and resumes. Offline, the
  recorded dunning lifecycle replays in order, shuffled and duplicated, with identical
  subscription facts and side effects every pass.

## The one sentence

`laravel/cashier` ^16.8 arrives with **`Organization` as the customer**, `Subscription`
as a tenant-owned model, and a webhook that resolves the tenant from the billable before
Cashier writes anything. The plan and price catalog is configuration; the database holds
no projection of it at M4.

## What already exists and is not rebuilt

| Existing                                                                | What M4 does with it                                                                                                                                             |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `App\Enums\Permission::ManageBilling` (`organization.manage_billing`)   | **Nothing.** The permission exists, the migration seeds it, and `OrganizationRole::Admin` already grants it. M4 adds no permission migration.                    |
| `App\Models\Organization` — not `TenantOwned`, bounded by membership    | Becomes the Cashier customer. `Cashier::findBillable()` queries it with no tenant resolved, which is legal today and needs no new audited door.                  |
| `organizations.owner_id` (D23)                                          | Read to decide who is billed, exactly as D23 anticipated. Never written by M4.                                                                                   |
| `App\Tenancy\TenantContext::runFor()`                                   | The webhook's tenant resolver. No second middleware, no second resolver.                                                                                         |
| `HandleInertiaRequests::present()` — projects an organization to 4 keys | Already immune to leaking `stripe_id` / `pm_last_four` into every page payload. Left exactly as it is, and a test locks that.                                    |
| `App\Concerns\ChecksOrganizationPermissions`                            | `SubscriptionPolicy` uses the same ladder — resolved tenant, active membership, owner floor, then the permission. No new authorization idiom.                    |
| `App\Actions\DeleteUser` + `OwnershipTransferRequired` (D25)            | Gains a second named refusal in the same shape: an account with unresolved billing cannot be closed (D34).                                                       |
| `tests/Support/TenantQueryGuard`                                        | Extended to watch `subscriptions`, with the limits D32 records.                                                                                                  |
| `app/Actions/RemoveOrganizationMember::wouldLeaveNobodyInCharge()`      | The house pattern for "two requests must not both read the same stale answer". The checkout action reuses its `lockForUpdate()` shape rather than inventing one. |
| `phpunit.xml` — `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`            | Already what `cashier-dunning` requires of a replay host. Nothing to change for the suite; the CI shell environment needs the same.                              |

Nothing above is rewritten. The only existing file whose _behaviour_ changes is
`app/Actions/DeleteUser.php`, and only by adding a refusal before the work it already does.

## Decisions this milestone proposes

### D32 — The organization is the Cashier customer, `Subscription` is tenant-owned, and the webhook resolves the tenant from the billable

`Cashier::useCustomerModel(Organization::class)`. `App\Models\Subscription` extends
`Laravel\Cashier\Subscription` and uses `BelongsToOrganization`, so it carries the global
scope that raises when no organization is resolved. `Cashier::ignoreRoutes()`; the webhook
route is registered by this application, pointing at a `WebhookController` subclass that
overrides exactly one method.

**Why this is safe, verified rather than assumed:** all nine handlers in
`laravel/cashier@v16.8.0/src/Http/Controllers/WebhookController.php` resolve the customer
first (`getUserByStripeId()`, line 352, which is `Cashier::findBillable()`) and then reach
subscriptions through the relation (`$user->subscriptions()`, lines 83 and 137). **No
Cashier handler queries `subscriptions` without an organization predicate.** So the tenant
is recoverable at the top of every webhook, and D22's and D27's list of audited doors
stays at two.

**The customer id is not in one place.** Subscription and invoice events carry
`data.object.customer`; `customer.updated` (line 242) and `customer.deleted` (line 257)
carry `data.object.id`. The override implements `customerIdFor(array $payload): ?string`
handling both shapes, with a test per handled event type. A payload with no resolvable
customer id runs the parent handler untenanted, which is correct — Cashier's own null
path already covers it.

**What this is explicitly not:** it is not a claim that `TenantQueryGuard` proves the
boundary here. The guard returns early on any SQL containing the literal string
`organization_id` before it checks which table was touched, so it waves `subscriptions`
through unconditionally. `subscriptions` is registered with the guard anyway — a query
genuinely missing the predicate still fails — but the load-bearing proof is the global
scope plus the `retrieved` and `saving` guards in `BelongsToOrganization`, asserted
directly by `tests/Feature/Billing/BillingBoundaryTest.php`.

**Known limit, stated rather than discovered:** `subscription_items` carries no
`organization_id` (Cashier's migration is `$table->foreignId('subscription_id')`). It is
reached only through a scoped parent and Cashier eager-loads it via `protected $with =
['items']`. It is therefore parent-scoped, not tenant-scoped, and is not registered with
the guard. A future direct query against `subscription_items` is unguarded; adding the
column is the fix when that day arrives.

**Cost accepted:** the webhook route registration and one controller method are now owned
by this project, so a Cashier upgrade reshaping `handleWebhook` must be read rather than
merged. The same cost D28 accepted for middleware order and D29 for `config/permission.php`.

### D33 — A billing mutation names the organization it intends to bill

Every billing write carries the organization slug in the request body. The form request
compares it against the resolved tenant and refuses a mismatch with 409 and a message
telling the user the organization changed elsewhere.

**Why:** D26 put the current organization in the session rather than the URL, which is
right for reads and for most writes. It is not right for a charge. Open billing for
organization A, switch to B in another browser tab, return to the first tab and submit:
`ResolveTenantContext` resolves B from the session and **B is billed for a plan chosen for
A**. There is no URL segment and no request field to contradict it. D1 exists so that
billing and tenancy cannot drift apart; this is them drifting apart inside one user's
browser.

**Scope of the rule:** billing mutations only. The same latent mismatch exists on the
members and invitations screens, where the cost is an action taken in the wrong
organization rather than money moved. That is recorded in `TODOS.md` and is deliberately
not fixed here, because widening it reopens D26 one milestone after it was decided.

### D34 — An account with unresolved billing cannot be closed

`DeleteUser` gains a second refusal, `BillingMustBeResolved`, thrown before any deletion
when an organization the user solely owns holds a subscription that is not fully ended.

**Why:** `app/Actions/DeleteUser.php:35-38` deletes organizations the user owns once the
shared-ownership check passes. A **personal** organization is not shared, so it is deleted
outright. After M4 that organization can hold an active Stripe subscription: the local row
vanishes, Stripe keeps charging the card, and every later webhook resolves no billable and
is silently dropped. The customer has no account, no screen, and a recurring charge. M4
creates this defect; it does not exist today.

**Why refusal rather than cancelling inside the deletion:** D25 already answered this
shape of question — refuse the destructive act and name the remedy. Cancelling inside the
transaction puts a network call where a timeout strands state: either the account survives
after the user was told it would not, or it is deleted with the subscription still live.

**A subscription on its grace period still refuses.** Cancelled-but-not-ended means Stripe
has a record that has not closed; the remedy message says when it ends.

### D35 — The catalog is configuration; billing facts are read through one reader

`config/billing.php` holds plans, their Stripe price ids, and a per-price feature allowance
map. There are no `plans` or `prices` tables at M4. `App\Billing\PlanCatalog` is the only
reader of that configuration, and `App\Billing\BillingFacts` is the only class permitted to
ask Cashier a subscription question. An architecture test asserts both.

**Why no tables:** a projection whose first reader arrives at M6 is D21's rejected pattern
("an abstraction whose first consumer arrives four milestones later is a guess"). M6 can
render from the same configuration.

**Why the allowance map ships unread:** `impruthvi/cashier-entitlements` constructs its
`PriceCatalog` from exactly this shape — price id to plan name to feature allowances — and
M5 builds it from the same array without reshaping a file this kit has already published.
One unread key for one milestone is the price of not breaking adopters' configuration at
M5. Stated here so it reads as a decision rather than an oversight.

**Why a reader class:** Cashier reads relations as properties.
`ManagesSubscriptions::subscription()` (line 157) is `$this->subscriptions->where(...)`,
and `Subscription::cancel()` (line 1132) is `$this->owner->stripe()->...`. This application
enables `ShouldBeStrict` in **every** environment (`config/essentials.php:165`), so
`preventLazyLoading` raises on both when the model was retrieved rather than just created
(`HasAttributes.php:620-624`). One reader that eager-loads first is the same answer
`MembershipRepository` gave to the same class of problem, and an arch test keeps it true.

**Eager loading is specified per path, not assumed.** `loadMissing('subscriptions.items')`
does **not** populate each subscription's inverse `owner`. Three paths, three explicit
guarantees: `BillingFacts` loads `subscriptions.items` for reads; the cancel and resume
actions call `setRelation('owner', $organization)` before handing a subscription to
Cashier; the webhook path is exempted from the arch test because Cashier builds its own
instances there, and its coverage comes from the replay instead.

### D36 — A webhook this application cannot place is retained, not retried

`TenantContextMissing` and `CrossTenantAccess` raised inside webhook processing are caught,
the raw payload is written to `failed_webhook_events`, and the response is 200. Every other
exception still propagates, so a genuine transient failure keeps Stripe's retry.

**Why not simply 200 and log:** those two exceptions do not prove permanent invalidity —
they can equally mean a bad deploy — and a 200 discards Stripe's delivery including after
the bug is fixed. A log line is not a recovery workflow.

**Why not simply let them throw:** Stripe retries for up to three days. An event that is
genuinely unplaceable, such as one for an organization that has since been deleted,
becomes a three-day error rate on the endpoint.

Retaining the payload dissolves the disagreement: nothing is lost and nothing is retried
pointlessly. The table is also the thing M6's stated "webhook replay" and "webhook
timeline" surfaces read, and it is what makes D8's final clause — "inspect the webhook
event that set it" — literally true.

**Retention is permanent at M4** and the payloads are raw Stripe JSON. M6 owns pruning.

### D37 — Every Stripe write that can be retried carries a stable idempotency key

Customer creation and checkout-session creation pass an idempotency key derived from the
organization key and the purpose of the call.

**Why a lock is not enough:** the checkout action locks the organization row so two
concurrent requests cannot both create a Stripe customer. That is necessary and
insufficient. The Stripe call succeeds before the surrounding transaction commits, so a
rollback afterwards leaves a customer in Stripe with no local `stripe_id`, and the next
attempt creates a second one. **A database lock cannot roll back Stripe.** Idempotency
keys are Stripe's documented answer, and the Stripe call is moved outside the transaction
that writes locally.

**Key shape binds:** too stable and a legitimate second checkout months later is
deduplicated into the first; too volatile and the key does nothing. Customer creation keys
on the organization alone, because an organization has exactly one Stripe customer for its
lifetime. Checkout-session creation keys on the organization plus the price plus a coarse
time bucket, so a retried click reuses the session and a deliberate later attempt does not.

## Architecture

### Where the tenant comes from, per entry point

```
  BROWSER (session-resolved tenant)              STRIPE (no session, no tenant)
  ─────────────────────────────────              ──────────────────────────────
  POST /organizations/billing/checkout           POST /stripe/webhook
        │                                              │
        ▼                                              ▼
  ResolveTenantContext          (D26)            (outside the web group entirely:
        │  session -> TenantContext                Cashier registers no middleware,
        ▼                                          and we keep it that way)
  SubstituteBindings            (D28)                  │
        │                                              ▼
        ▼                                        customerIdFor($payload)
  CheckoutRequest                                      │   data.object.customer
        │  organization slug in body (D33)             │   data.object.id
        │  MUST equal resolved tenant  ──► 409         ▼
        ▼                                        Cashier::findBillable()
  Gate::authorize('create', Subscription)              │  queries `organizations`
        │  ChecksOrganizationPermissions ladder        │  (not tenant-owned: legal)
        ▼                                              ▼
  StartBillingCheckout                           TenantContext::runFor($org)   (D32)
        │  lockForUpdate on the org row                │
        │  idempotency key          (D37)              ▼
        ▼                                        parent::handleWebhook()
  Stripe (outside the local transaction)               │  every write now scoped
        │                                              │
        ▼                                              ├─ TenantContextMissing ─┐
  Inertia::location($url)        (D3)                  ├─ CrossTenantAccess ────┤
        │  409 + X-Inertia-Location                    │                        ▼
        ▼                                              │              failed_webhook_events
  checkout.stripe.com                                  │              + 200            (D36)
                                                       └─ anything else ──► throw ──► 500
                                                                                (Stripe retries)
```

### Subscription states the screen must render

```
        no subscription
              │
              │  checkout completes, webhook lands
              ▼
      ┌──► active ──────────────┐
      │       │                 │  cancel()
      │       │ payment fails   ▼
      │       ▼           on grace period
      │   past_due          (ends_at future)
      │       │                 │       │
      │       │ recovers        │       │  resume()
      └───────┘                 │       └──────────► active
                                │
                                │  ends_at passes
                                ▼
                             ended
                       (resume refused:
                     SubscriptionNotCancelled)
```

Four states are reachable from the screen and all four are rendered by
`BillingFacts`: none, active, on grace period, past_due. `ended` renders as none with the
catalog offered again.

## Scope

### In scope

- `laravel/cashier` ^16.8 in `require`; `impruthvi/cashier-dunning` ^0.2.1 in `require-dev` (D2).
- Cashier's three migrations, published and edited: customer columns retargeted at
  `organizations` keeping the `stripe_id` index; `subscriptions.user_id` renamed to
  `organization_id` **including the composite index** `['organization_id','stripe_status']`.
- `App\Models\Subscription` (tenant-owned) and `App\Models\SubscriptionItem`.
- `Organization` gains `Billable`, overrides `stripeEmail()` / `stripeName()` to read the
  owner (D35's loading rules apply).
- `config/billing.php`, `App\Billing\{PlanCatalog, Plan, Price, BillingFacts}`.
- `App\Providers\BillingServiceProvider` and `App\Providers\BillingReplayServiceProvider`.
- `App\Actions\{StartBillingCheckout, CancelSubscription, ResumeSubscription}`.
- `App\Http\Controllers\Billing\{BillingController, CheckoutController, SubscriptionController,
StripeWebhookController}`; `App\Http\Requests\Billing\{CheckoutRequest, SubscriptionActionRequest}`.
- `App\Policies\SubscriptionPolicy`; four exceptions in `App\Exceptions\Billing\`.
- `failed_webhook_events` table (D36); `BillingMustBeResolved` and the `DeleteUser`
  refusal (D34).
- `resources/js/pages/billing/Index.vue`.
- The Playwright and `Browser` suite fix, as its own commit, plus one billing browser test.
- `composer test:billing` in `ci:check`: `billing:doctor`, ordered replay, seeded
  `--shuffle --duplicate` pass.
- `docs/sandbox-validation.md` — the dated record of the one manual Stripe test-mode run.

### NOT in scope

Each of these was considered during the review and deferred with a reason.

- **`plans` / `prices` database tables.** No reader until M6, which can render from
  configuration (D35).
- **Plan switching and proration.** Not on the D8 journey sentence, and proration policy is
  an unmade product decision.
- **Invoices, payment-method update, billing portal.** M6 surfaces; none is on the journey.
- **Seats and billed quantity.** `CONTEXT.md` is explicit that a seat is a billed quantity;
  `TODOS.md` already blocks the invitation-seat question on M5's limit enforcement.
- **Billing-status caching.** `cashier-entitlements` resolves locally with no request-scoped
  cache. A cache built at M4 is deleted at M5.
- **Event-age guarding / webhook convergence.** Cashier is last-write-wins within a step, so
  an out-of-order `active` update can restore a cancelled subscription. M4 therefore
  asserts subscription facts and side effects, **not** resolved allowances, and registers
  no entitlement resolver for the replay (D18). The extension point is
  `CashierDunning::resolveEntitlementsUsing()`, which M5 fills with `LocalResolver`.
- **Publishing `config/cashier.php`.** Every value is env-driven and `ignoreRoutes()` makes
  `cashier.path` moot. M4 adds zero owned config surface on top of D28's and D29's.
- **Session-tenancy mismatch guards outside billing.** D33 is scoped to money; widening it
  reopens D26. Recorded in `TODOS.md`.
- **Syncing the Stripe customer email on ownership transfer.** Known gap from D35's owner
  read. Recorded in `TODOS.md`.
- **Pruning `failed_webhook_events`.** Retention is permanent at M4; M6 owns it.
- **`organization_id` on `subscription_items`.** Parent-scoped is sufficient while no code
  queries it directly (D32).

## Tests

### Coverage diagram

Every path is new; existing billing coverage is zero by construction. No regressions —
M4 adds code, and the only existing behaviour it changes is `DeleteUser`, which gains a
refusal covered by its own test.

```
CODE PATHS                                                     USER FLOWS
[+] BillingServiceProvider                                      [+] Subscribe to Pro
  ├── PlanCatalog singleton from config                           ├── admin opens /organizations/billing
  ├── Cashier::use*Model + ignoreRoutes                           ├── [→E2E] clicks Subscribe, leaves the SPA
  └── Cashier's own routes NOT registered                         ├── returns to success_url, plan visible
                                                                   ├── double-click -> ONE stripe customer
[+] BillingReplayServiceProvider                    (D11)         └── stale tab after switching org -> 409  (D33)
  ├── absent from the production provider list
  └── dunning closures registered outside production             [+] Cancel and resume
                                                                   ├── cancel -> "ends on <date>"
[+] PlanCatalog / Plan / Price                      (D35)         ├── resume during grace -> active
  ├── priceIds() feeds Rule::in                                   └── resume after ended -> refused
  ├── findPrice() known -> Price / unknown -> null
  ├── duplicate price id across plans -> refused                  [+] Authorization
  └── malformed config -> refused at boot                          ├── member sees no controls
                                                                   ├── member POSTs anyway -> 403
[+] Organization (Billable)                         (D35)         ├── org A cancels org B -> refused
  ├── stripeEmail() -> owner's email                               └── owner without the role still allowed
  ├── owner not loaded -> no LazyLoadingViolation
  └── Inertia props still carry 4 keys only                       [+] Error states the user sees
                                                                   ├── Stripe down -> recoverable message
[+] Subscription (TenantOwned)                      (D32)         ├── unknown price -> field-level 422
  ├── query with no tenant -> TenantContextMissing                 ├── already subscribed -> named message
  ├── retrieved wrong tenant -> CrossTenantAccess                  └── org archived -> named message
  ├── org A cannot read org B's subscription
  └── subscription_items parent-scoped    (KNOWN LIMIT)           [+] Webhook, offline
                                                                   ├── [→E2E] full dunning lifecycle
[+] StartBillingCheckout                       (D14, D37)         ├── [→E2E] shuffled within a step
  ├── happy -> Checkout                                            ├── [→E2E] duplicated delivery
  ├── already subscribed -> AlreadySubscribed                      ├── unknown stripe_id -> 200, no row
  ├── org not usable -> OrganizationNotBillable                    └── deleted org -> retained + 200   (D36)
  ├── concurrent -> one customer (lockForUpdate)
  ├── idempotency key stable across retries                       [+] Account closure                  (D34)
  └── ApiErrorException propagates, not swallowed                  ├── active subscription -> refused
                                                                   ├── on grace period -> refused
[+] CancelSubscription / ResumeSubscription                        └── ended -> allowed
  ├── cancel active -> grace period
  ├── cancel when none -> NoActiveSubscription                    [+] Boundary / arch
  ├── resume on grace -> active                                    ├── no file queries where('user_id')
  ├── resume when not cancelled -> SubscriptionNotCancelled        ├── Subscription is TenantOwned
  └── owner relation set before Cashier is called    (D35)         ├── no new withoutTenantScope caller
                                                                   ├── subscribed(/subscription( only in Billing/
[+] Controllers                                                    └── production providers exclude the replay one
  ├── index renders 4 states
  ├── store -> 409 + X-Inertia-Location, never a 303  (D3)
  ├── organization mismatch -> 409                    (D33)
  ├── Gate refuses without ManageBilling
  ├── billing exceptions -> back()->withErrors
  └── ApiErrorException -> flash + log                (D10)

[+] StripeWebhookController                         (D32, D36)
  ├── customerIdFor: data.object.customer shape
  ├── customerIdFor: data.object.id shape (customer.updated/deleted)
  ├── no resolvable id -> parent runs untenanted
  ├── billable not found -> Cashier's null path
  ├── TenantContextMissing -> retained + 200
  ├── CrossTenantAccess -> retained + 200
  └── anything else -> 500, Stripe retries

TARGET: 61/61 paths tested (100%)  |  Code paths: 37  |  User flows: 24
```

### Test files

| File                                                            | Covers                                                     |
| --------------------------------------------------------------- | ---------------------------------------------------------- |
| `tests/Unit/BillingCatalogTest.php`                             | `PlanCatalog`, duplicate and malformed configuration       |
| `tests/Unit/ArchTest.php` (extended)                            | `user_id` ban, `subscribed(` confinement, provider list    |
| `tests/Unit/TenantScopingTest.php` (extended)                   | `Subscription` is `TenantOwned`; no new escape callers     |
| `tests/Feature/Billing/BillingScreenTest.php`                   | Four rendered states; props carry no Stripe columns        |
| `tests/Feature/Billing/CheckoutTest.php`                        | Validation, 409 location, mismatch 409, Stripe failure     |
| `tests/Feature/Billing/SubscriptionLifecycleTest.php`           | Cancel, grace, resume, refusals                            |
| `tests/Feature/Billing/BillingAuthorizationTest.php`            | The ladder, the owner floor, the member refusal            |
| `tests/Feature/Billing/BillingBoundaryTest.php`                 | Cross-tenant reads and writes on `Subscription`            |
| `tests/Feature/Billing/StripeWebhookTest.php`                   | Both id shapes, tenant resolution, retention, retry policy |
| `tests/Feature/Billing/CheckoutConcurrencyTest.php`             | Parallel checkout, one customer, stable idempotency key    |
| `tests/Feature/Authorization/AccountClosureTest.php` (extended) | D34's refusals                                             |
| `tests/Browser/BillingTest.php`                                 | Subscribe actually navigates away from the app             |

### Test traps this suite will hit

- **`Http::preventStrayRequests()` does not cover Stripe.** Cashier's calls go through the
  Stripe SDK's own `ApiRequestor`, not Laravel's HTTP client, so a stray real call is
  possible if a key is present in the environment. Every test that reaches Cashier binds
  the fake; `phpunit.xml` must also pin `STRIPE_KEY` and `STRIPE_SECRET` to inert values.
- **`TenantQueryGuard` waves `subscriptions` through.** Any SQL containing the string
  `organization_id` returns early before the table check. The boundary tests assert the
  global scope and the `retrieved` guard directly rather than relying on the guard.
- **`vendor/bin/pest --agent` cannot verify any of this.** It runs from a temp path outside
  `Browser`/`Feature`/`Unit`, so `tests/Pest.php`'s `beforeEach` never fires: no query
  guard, no authorization team guard, no frozen time. Write real test files.
- **Automatic eager loading is on in production and off in tests.** `tests/Pest.php:33`
  disables it so the lazy-loading guard can fail. A path that only production exercises is
  therefore unguarded — which is precisely why D35 specifies loading per path.
- **A dunning replay owns its transaction.** Application code calling `DB::commit()` fails
  the run by design, and `->afterCommit()` callbacks cannot execute inside it. Keep billing
  side effects out of `afterCommit`.

## Failure modes

| Codepath                  | Realistic production failure                               | Test?  | Handled?  | User sees                                   |
| ------------------------- | ---------------------------------------------------------- | ------ | --------- | ------------------------------------------- |
| `StartBillingCheckout`    | Stripe rate-limits or times out                            | yes    | yes (D10) | "Payment provider unavailable, try again"   |
| `StartBillingCheckout`    | Transaction rolls back after Stripe created the customer   | yes    | yes (D37) | nothing; the retry reuses the same customer |
| `CheckoutController`      | User submits a form from a stale tab                       | yes    | yes (D33) | 409, "this organization changed elsewhere"  |
| `StripeWebhookController` | Event for an organization since deleted                    | yes    | yes (D36) | nothing; row retained, Stripe not retried   |
| `StripeWebhookController` | `customer.updated` with the `data.object.id` shape         | yes    | yes (D32) | nothing; tenant resolves correctly          |
| `StripeWebhookController` | Out-of-order `active` after `deleted` restores stale state | **no** | **no**    | stale plan until the next event corrects it |
| `Subscription::cancel()`  | `$this->owner` lazy-loads on a retrieved model             | yes    | yes (D35) | nothing; relation set explicitly            |
| `DeleteUser`              | Account closed while Stripe still charges                  | yes    | yes (D34) | refusal naming the remedy                   |
| `BillingFacts`            | Organization has no subscription at all                    | yes    | yes       | catalog offered                             |

**One critical gap, accepted and named: out-of-order webhook convergence.** No test, no
error handling, and the failure is silent — a stale plan until a later event corrects it.
It is accepted at M4 because fixing it means overriding most of Cashier's nine handlers to
add event-age guarding, which belongs with entitlement resolution at M5. D18 records the
reasoning; the milestone's replay therefore asserts subscription facts, not allowances, so
no test claims a guarantee that does not exist.

## Parallelization

| Step                                             | Modules touched                                                          | Depends on |
| ------------------------------------------------ | ------------------------------------------------------------------------ | ---------- |
| T1 Playwright + Browser suite                    | `package.json`, `phpunit.xml`, `.github/`                                | —          |
| T2 Cashier install, migrations, models, provider | `composer.json`, `database/migrations/`, `app/Models/`, `app/Providers/` | —          |
| T3 Boundary + arch tests for the new models      | `tests/`                                                                 | T2         |
| T4 Catalog: config + value objects               | `config/`, `app/Billing/`                                                | —          |
| T5 `BillingFacts` + its arch test                | `app/Billing/`, `tests/`                                                 | T2, T4     |
| T6 Policy, routes, read-only billing screen      | `app/Policies/`, `routes/`, `app/Http/Controllers/Billing/`              | T5         |
| T7 Checkout: request, action, controller         | `app/Actions/`, `app/Http/`, `app/Exceptions/Billing/`                   | T6         |
| T8 Cancel and resume                             | `app/Actions/`, `app/Http/`                                              | T7         |
| T9 Webhook + `failed_webhook_events`             | `app/Http/Controllers/Billing/`, `database/migrations/`                  | T2         |
| T10 `DeleteUser` refusal                         | `app/Actions/`, `app/Exceptions/`                                        | T5         |
| T11 Vue billing screen + browser test            | `resources/js/`, `tests/Browser/`                                        | T1, T7, T8 |
| T12 Dunning wiring, `test:billing`, CI           | `app/Providers/`, `composer.json`, `.github/`                            | T2, T4     |
| T13 Decision records and docs                    | `docs/`                                                                  | everything |

```
Lane A:  T1                                    (independent, no app code)
Lane B:  T2 -> T3 -> T5 -> T6 -> T7 -> T8      (models, billing, controllers)
Lane C:  T4                                    (config + value objects, independent)
Lane D:  T9                                    (webhook; needs T2 only)
Lane E:  T10                                   (DeleteUser; needs T5)

Launch A and C in parallel worktrees immediately. Start B as soon as T2 lands.
D joins after T2. E joins after T5. Then T11, T12, T13 sequentially.
```

**Conflict flags.** Lane B (T7, T8) and Lane E (T10) both write to `app/Actions/` —
different files, but expect a merge touch. Lane B (T6, T7) and Lane D (T9) both write to
`app/Http/Controllers/Billing/` — same directory, different files. Lane A and Lane B both
touch `.github/workflows/tests.yml` if T12 lands early; keep T12 last to avoid it.

## Implementation tasks

Synthesized from this review's findings. Each derives from a specific decision above.

- [ ] **T1 (P2, human: ~1d / CC: ~1h)** — test infra — Make `tests/Browser` actually run
    - Surfaced by: Test review D13; pre-existing `TODOS.md` entry
    - Files: `package.json`, `phpunit.xml`, `.github/workflows/tests.yml`
    - Verify: `vendor/bin/pest tests/Browser` runs `WelcomeTest` and `MembersManagementTest` green
- [ ] **T2 (P1, human: ~1d / CC: ~50min)** — billing core — Cashier on `Organization`
    - Surfaced by: D32, D35 (`stripeEmail`), D8 (no published config)
    - Files: `composer.json`, 3 published migrations, `app/Models/{Subscription,SubscriptionItem}.php`, `app/Models/Organization.php`, `app/Providers/BillingServiceProvider.php`
    - Verify: `php artisan migrate:fresh`; `subscriptions` has `organization_id` and the composite index
- [ ] **T3 (P1, human: ~6h / CC: ~35min)** — tenancy — Prove the subscription boundary directly
    - Surfaced by: D32 — the query guard cannot prove this table
    - Files: `tests/Feature/Billing/BillingBoundaryTest.php`, `tests/Unit/{ArchTest,TenantScopingTest}.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing tests/Unit`
- [ ] **T4 (P1, human: ~5h / CC: ~30min)** — catalog — `config/billing.php` and its value objects
    - Surfaced by: D35
    - Files: `config/billing.php`, `app/Billing/{PlanCatalog,Plan,Price}.php`, `tests/Unit/BillingCatalogTest.php`
    - Verify: `vendor/bin/pest --filter=BillingCatalog`
- [ ] **T5 (P1, human: ~5h / CC: ~35min)** — billing reads — `BillingFacts` and the loading guarantees
    - Surfaced by: D35; outside voice #7 (inverse `owner` is not loaded by `subscriptions.items`)
    - Files: `app/Billing/BillingFacts.php`, `tests/Unit/ArchTest.php`
    - Verify: a feature test reading a subscription raises no `LazyLoadingViolationException`
- [ ] **T6 (P1, human: ~6h / CC: ~35min)** — authorization + screen — Policy, routes, read-only billing page
    - Surfaced by: Code quality C5 (one `can()` call, derived props)
    - Files: `app/Policies/SubscriptionPolicy.php`, `routes/billing.php`, `app/Http/Controllers/Billing/BillingController.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing/BillingAuthorizationTest.php`
- [ ] **T7 (P1, human: ~1.5d / CC: ~1h15)** — checkout — Start a subscription
    - Surfaced by: D3, D9, D33, D37, D10
    - Files: `app/Http/Requests/Billing/CheckoutRequest.php`, `app/Actions/StartBillingCheckout.php`, `app/Http/Controllers/Billing/CheckoutController.php`, `app/Exceptions/Billing/*`
    - Verify: `vendor/bin/pest tests/Feature/Billing/{CheckoutTest,CheckoutConcurrencyTest}.php`
- [ ] **T8 (P1, human: ~6h / CC: ~35min)** — lifecycle — Cancel and resume
    - Surfaced by: D1 scope (the way out); D35 (set the owner relation first)
    - Files: `app/Actions/{CancelSubscription,ResumeSubscription}.php`, `app/Http/Controllers/Billing/SubscriptionController.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing/SubscriptionLifecycleTest.php`
- [ ] **T9 (P1, human: ~1d / CC: ~50min)** — webhook — Resolve the tenant, retain what cannot be placed
    - Surfaced by: D32, D36; outside voice #6 (two customer id shapes)
    - Files: `app/Http/Controllers/Billing/StripeWebhookController.php`, `database/migrations/*_create_failed_webhook_events_table.php`, `routes/billing.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing/StripeWebhookTest.php`
- [ ] **T10 (P1, human: ~4h / CC: ~25min)** — account closure — Refuse while billing is unresolved
    - Surfaced by: D34; outside voice #3
    - Files: `app/Actions/DeleteUser.php`, `app/Exceptions/BillingMustBeResolved.php`, `tests/Feature/Authorization/AccountClosureTest.php`
    - Verify: `vendor/bin/pest tests/Feature/Authorization/AccountClosureTest.php`
- [ ] **T11 (P2, human: ~1d / CC: ~50min)** — frontend — The billing screen and its browser test
    - Surfaced by: D13; D3 (only a browser can prove the navigation)
    - Files: `resources/js/pages/billing/Index.vue`, `tests/Browser/BillingTest.php`
    - Verify: `php artisan wayfinder:generate && npm run build && vendor/bin/pest tests/Browser/BillingTest.php`
- [ ] **T12 (P1, human: ~1d / CC: ~50min)** — proof — Dunning replay as a CI gate
    - Surfaced by: D6, D11, D18
    - Files: `app/Providers/BillingReplayServiceProvider.php`, `bootstrap/providers.php`, `composer.json`, `.github/workflows/tests.yml`
    - Verify: `composer test:billing` green; ordered and `--shuffle --duplicate --seed=7` both pass
- [ ] **T13 (P2, human: ~5h / CC: ~30min)** — docs — Record D32-D37 and mark M4 against its proof
    - Surfaced by: repository convention (`git log` cites decision numbers)
    - Files: `docs/decisions/0001-architecture-decisions.md`, `docs/plans/0001-vertical-slice.md`, `docs/sandbox-validation.md`, `TODOS.md`
    - Verify: M4's paragraph in `0001-vertical-slice.md` matches what shipped, including the allowance deferral

## Inline diagrams the implementation should carry

- `app/Http/Controllers/Billing/StripeWebhookController.php` — the per-entry-point tenant
  diagram above, trimmed to the inbound half. This is the only place in the codebase where
  a tenant is resolved from an untrusted payload, and the next reader deserves the map.
- `app/Models/Subscription.php` — the state machine above. Four states reach the screen and
  the transitions are not obvious from Cashier's column names.
- `app/Actions/StartBillingCheckout.php` — the ordering of lock, Stripe call and local
  transaction, because D37's whole point is that the Stripe call sits _outside_ the
  transaction and a future refactor will want to move it back in.
- `app/Billing/BillingFacts.php` — which relations are loaded where, per D35's three paths.

## GSTACK REVIEW REPORT

| Review        | Trigger               | Why                             | Runs | Status       | Findings                                                           |
| ------------- | --------------------- | ------------------------------- | ---- | ------------ | ------------------------------------------------------------------ |
| CEO Review    | `/plan-ceo-review`    | Scope & strategy                | 0    | —            | —                                                                  |
| Codex Review  | `/codex review`       | Independent 2nd opinion         | 0    | —            | —                                                                  |
| Eng Review    | `/plan-eng-review`    | Architecture & tests (required) | 1    | CLEAR        | 20 issues, 1 critical gap                                          |
| Design Review | `/plan-design-review` | UI/UX gaps                      | 0    | —            | —                                                                  |
| DX Review     | `/plan-devex-review`  | Developer experience gaps       | 0    | —            | —                                                                  |
| Outside Voice | `/plan-eng-review`    | Cross-model plan challenge      | 3    | issues_found | 8 findings, 5 folded, 2 accepted as corrections, 1 already covered |

- **CROSS-MODEL:** Codex raised two P0s the four review sections missed, both at the seam
  where billing meets an existing subsystem: a stale browser tab billing the wrong
  organization through session-held tenancy (D33), and account deletion destroying a
  personal organization while Stripe keeps charging (D34). It also correctly refined three
  decisions — the two Stripe customer-id payload shapes (D32), the inverse `owner` relation
  that `subscriptions.items` does not load (D35), and idempotency keys over a database lock
  (D37) — and correctly challenged the honesty of the replay's allowance assertion (D18).
  Neither reviewer disputed the architecture: tenant-owned `Subscription` with webhook-side
  tenant resolution, `Inertia::location` for the external redirect, a dedicated billing
  provider, and no published `config/cashier.php` stand unchallenged by both.
- **VERDICT:** ENG CLEARED — ready to implement. One critical gap is accepted and named:
  out-of-order webhook convergence has no test and no handling, and fails silently; D18
  records why it belongs with entitlement resolution at M5, and M4's replay deliberately
  asserts no guarantee that depends on it.

NO UNRESOLVED DECISIONS
