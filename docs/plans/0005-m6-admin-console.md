# 0005 — M6, the admin console

- **Date:** 2026-09-24
- **Status:** Proposed. Drafted and reviewed by `/plan-eng-review` including a Codex
  outside voice; see the review report at the end. Fifteen decisions were taken (D2–D14
  plus three TODO dispositions). Seven changed the first draft's behaviour: the
  impersonation session, audit attribution, the inspector's event link, replay checks,
  revocation, `applied_at`, and the dead-navigation proof.
- **Branch:** `feat/m6-admin-console`, to be cut from `origin/main` (M5 merged as `d01fbc4`).
- **Milestone:** M6 of `plans/0001-vertical-slice.md`.
- **Decisions it rests on:** D1, D3, D4, D8, D11, D12, D20, D22, D24, D26, D27, D28, D29,
  D32, D35, D36, D38, D41, D42, D44, D45, and `0002` §4 (`laravel/chisel` is load-bearing).
- **Decisions it proposes:** D46, D47, D48, D49, D50, D51, D52.
- **Proof it is done:** two things, and both must hold.
    1. **The journey's last clause runs.** An operator signs in through the ordinary Fortify
       login, finds the organization that just subscribed to Pro, and sees its resolved
       `projects` entitlement (Pro, 10, source: package), its usage counter (n / 10), and
       the Stripe events that requested the refresh that produced that entitlement, with
       `customer.subscription.created` marked primary and applied.
    2. **The module is removable.** A CI job runs the removal script, `composer remove
filament/filament`, and then the full PHP suite and the frontend build. Both are
       green. No product page links to a route that no longer exists.

## The one sentence

Filament arrives as a module that owns only screens and the operator list. It never
holds an ambient tenant. Every tenant-owned read goes through an application service
that names the organization it reads, and returns plain data. Every Stripe delivery is
recorded. The audit log and the end of an impersonation belong to the product, because
they have to outlive the console.

## Vocabulary this milestone adds to `CONTEXT.md`

The build plan says "customer lookup" and "admin". Both collide with words `CONTEXT.md`
already fixes, so they get settled here, before code names anything.

| Term                    | Meaning                                                                                                | Avoid                                                                                       |
| ----------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| **Operator**            | A person allowed into the admin console. A platform fact, unrelated to any organization.               | admin (that is a **rank**), superuser, staff                                                |
| **Admin console**       | The Filament panel operators use. The module that D4 makes removable.                                  | admin panel, backoffice, dashboard (that is the product)                                    |
| **Webhook event**       | One Stripe delivery as this application received it, with the outcome of applying it.                  | webhook log, event (alone), failed event (that is one outcome)                              |
| **Audit event**         | One intentional, attributed act that changed who can do what in an organization, or what it is billed. | activity, log entry, history                                                                |
| **Impersonation**       | A bounded, reasoned period in which an operator acts as a user. It has a start, an end, and an expiry. | login as, sudo, masquerade                                                                  |
| **Organization lookup** | Finding an organization by name, slug, Stripe customer id, or a member's email.                        | customer lookup (a **user** is never a customer, and Stripe's customer is the organization) |

## What already exists and is not rebuilt

| Existing                                                           | What M6 does with it                                                                                                                                                                                                                                                                                                                                                              |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `App\Tenancy\TenantContext::runFor()`                              | The only way the console reads tenant-owned data (D46). No new resolver, no third audited door.                                                                                                                                                                                                                                                                                   |
| `App\Tenancy\MembershipRepository::organizationsFor()`             | Organization lookup by member email. Already an audited door (D22); the console calls it rather than querying memberships.                                                                                                                                                                                                                                                        |
| `App\Entitlements\ResolveAllowance`                                | The inspector's allowance answer. The inspector adds only the _source_ of the answer (package, floor), never a second computation.                                                                                                                                                                                                                                                |
| `App\Billing\BillingFacts`                                         | The only reader of Cashier subscription state, still asserted by `ArchTest`. The console asks it; it never touches `$org->subscription()`.                                                                                                                                                                                                                                        |
| `App\Billing\PlanCatalog`                                          | Plan and allowance names for the inspector. No plan tables (D35).                                                                                                                                                                                                                                                                                                                 |
| `App\Models\FailedWebhookEvent` + `failed_webhook_events` (D36)    | **Superseded** by `webhook_events` (D48). Its rows are migrated, then the table is dropped.                                                                                                                                                                                                                                                                                       |
| `App\Models\SubscriptionEventWatermark` (D42)                      | Identifies which applied event last wrote a subscription row. Read-only here.                                                                                                                                                                                                                                                                                                     |
| `cashier_entitlement_states` / `_receipts` / `_usage_counters`     | The inspector's raw material. States and usage are read through `NativeStateStore::state()` and `OwnerAccess::usage()`. Receipts have no public reader, so `App\Entitlements\RefreshReceipts` reads `cashier_entitlement_receipts` read-only, keyed by the public `NativeStateStore::ownerId()`. It is the **only** direct reader of a package table, and an arch test pins that. |
| `Impruthvi\CashierEntitlements\Diagnostics\Doctor`                 | **Global only**: `report()` counts pending, failing and stale owners across all of them. It is the console home's health widget. Per-organization refresh health comes from `NativeStateStore::state()` (`requested_sequence`, `completed_sequence`, `last_error`, `retry_at`, `observed_at`).                                                                                    |
| `OrganizationStatus::isUsable()`                                   | Already honoured by tenant resolution, switching, invitations and checkout. M6 does **not** add the suspend write path (see NOT in scope).                                                                                                                                                                                                                                        |
| `laravel/chisel` (`RemoveInterfaceVisitor`, `RemoveImportVisitor`) | The removal script (D52). Exactly the job `0002` §4 promoted it for.                                                                                                                                                                                                                                                                                                              |
| `tests/Support/TenantQueryGuard` + `AuthorizationTeamGuard`        | Every console test runs under both. That is the proof D46 holds, rather than a claim that it does.                                                                                                                                                                                                                                                                                |

## Decisions this milestone proposes

### D46 — The admin console has no ambient tenant; it reads tenant-owned data only through services that name the organization

The console is cross-tenant by nature. Tenant-owned models raise without a resolved
organization (D3), and D22 and D27 capped the audited doors at two. So the console gets
no door at all. It is **organization-first**:

1. Screens over tables that are **not** tenant-owned (`organizations`, `users`,
   `webhook_events`, `impersonations`, `operators`) are ordinary Eloquent-backed
   Filament resources.
2. Every screen that shows tenant-owned data (memberships, invitations, projects,
   subscriptions, audit events, entitlement state) starts from an `Organization` already
   in hand and calls one read service in `app/Operations/`. That service does its work
   inside `TenantContext::runFor($organization)` and returns plain arrays or read-only
   DTOs. Operations only read. Anything that changes state is an Action in
   `app/Actions/`, resolves the organization the same way, and records an audit event.
3. Filament tables over that data use **custom data** (`Table::records()`), which
   Filament 5 supports with pagination. No Filament component ever builds an Eloquent
   query against a tenant-owned model.

**The trap this has to survive: Livewire requests run a different middleware stack from
panel page loads.** A panel page load runs the panel's own middleware list, which does
not include the `web` group, so no tenant is resolved. Livewire's `/livewire/update`
endpoint runs the `web` group, so `ResolveTenantContext` resolves the **operator's own
organization** from the session. A console service that forgot `runFor()` would then
read the operator's own projects and label them as the customer's. That is a silent
wrong-tenant read, the worst failure in the codebase. Two defences:

- A panel middleware, `ForgetTenantContext`, registered with `isPersistent: true` so
  Livewire replays it on every component update, calls `TenantContext::forget()`. The
  console then behaves identically on page loads and on updates: no tenant, and a
  forgotten `runFor()` raises `TenantContextMissing` instead of reading the wrong rows.
- Every console test signs in as an operator who is also a member of a **different**
  organization with seeded data, and asserts the customer's data appears and the
  operator's does not.

**Why not a third audited door.** A door is a query that cannot know its organization.
The console always knows it, because an operator navigates to an organization before
seeing anything inside it. Adding a door would widen the escape for convenience.

**Cost accepted:** custom-data tables lose Filament's built-in SQL sorting and filtering
on those screens. Each service implements the few sort orders and filters it offers.
Nothing tenant-owned is large enough per organization at V1 to make this matter.

### D47 — Operators are a table owned by the console, granted from the command line

`operators` (`user_id` unique, `granted_by`, `reason`, `granted_at`). `App\Models\User`
implements Filament's `FilamentUser`, and `canAccessPanel()` returns true only when:

- an `operators` row exists for the user,
- the email is verified,
- and outside `local` and `testing`, two-factor authentication is confirmed.

Operators are granted and revoked by `php artisan operators:grant {email} --reason=` and
`operators:revoke`, never through a web form. The first operator cannot be created from
a browser, so a stolen session cannot create a second one. Both commands write an audit
line to the application log. They cannot write an audit event, because an audit event
belongs to an organization and a grant does not (D49).

**Why not a Spatie role.** D30 made role assignments team-scoped with a non-null
`organization_id`. A platform role would need a null team, which D30's unique index
cannot constrain. **Why not a `users.is_operator` column.** The column would outlive the
module it gates, and it is one mass-assignment mistake away from a privilege escalation.
A separate table is dropped with the module and is never fillable from a profile form.

**Why not an email allowlist in config.** Fortify lets a user change their email. An
allowlist keyed on an address grants the console to whoever holds that address next.

Operators sign in through the product's Fortify login, so passkeys, two-factor
authentication and throttling apply unchanged. The panel registers no login page of its
own.

### D48 — Every Stripe delivery becomes a webhook event, and `failed_webhook_events` is folded into it

`webhook_events`: `stripe_event_id` (unique), `type`, `stripe_customer_id`,
`stripe_object_id`, `stripe_created_at`, `outcome`, `outcome_reason`, `applied_at`,
`deliveries`, `payload`, `first_received_at`, `last_received_at`. `outcome` is one of `applied`,
`superseded`, `unplaceable`, `errored`, `replayed`, `refused`.

`StripeWebhookController` writes it on every path it already has, and on the one it does
not: success. A redelivery upserts by `stripe_event_id` and increments `deliveries`, so
a redelivery that succeeds after an `errored` attempt moves the row to `applied`. The
existing `failed_webhook_events` rows migrate in as `unplaceable` or `superseded`, and
the table and model are removed. The fold groups by `stripe_event_id`, because the old
table has one row per delivery with a non-unique index: `deliveries` is the count, the
received-at bounds are the min and max, and the latest row supplies the payload and
reason. Rows with a null id are copied individually.

**It is a platform table, not a tenant-owned one.** An event arrives before any
organization is known, and an `unplaceable` event never has one. It is keyed by
`stripe_customer_id`, and the console reads one organization's events with
`where stripe_customer_id = $organization->stripe_id`. No `organization_id` column, so
`TenantScopingTest` does not demand it be tenant-owned. That is accurate rather than
evasive: the organization is derived, and for some rows it does not exist.

**Why a table rather than Stripe's dashboard.** D8's last clause is "the webhook event
that set it". Cashier stores no event history. Only subscription rows, overwritten in
place. Without this table that clause cannot be shown.

**Retention, answered with the two `TODOS.md` entries that asked for it.** A daily
`model:prune` over `App\Models\WebhookEvent`, which is `MassPrunable` (Laravel's own pruning, not a custom command), removes `applied` and `superseded` rows older than 90 days, and
`unplaceable`, `errored`, `refused` and `replayed` rows older than 180. The longer window
is for the rows a replay exists to recover, and a replayed row keeps the window of the
problem it recovered from. Age is measured from `last_received_at`.

**`outcome` is the latest delivery; `applied_at` is history.** When A is applied, a newer
B advances the watermark, and Stripe redelivers A, A's redelivery is `superseded`. That
must not erase the fact that A once changed state. `applied_at` is written on the first
successful application and never cleared. The inspector (R3) and the subscription
timeline read `applied_at`, not `outcome`. Entitlement package rows for deleted organizations stay inert
and unpruned, as D44 already argued. This decision records that position rather than
leaving it open.

**Cost accepted:** unredacted Stripe payloads for up to 180 days. Redaction is deferred:
it needs a field-by-field decision about what replay still needs.

### D49 — The audit log is part of the product, tenant-owned, append-only, and written by intent

`audit_events`: `organization_id`, `actor_id` (nullable: webhook and scheduled work have
no actor), `impersonation_id` (nullable), `action`, `subject_type`, `subject_id`,
`context` (json), `occurred_at`. `App\Models\AuditEvent` is `TenantOwned` and refuses
`update` and `delete` from its own model events. `App\Actions\RecordAuditEvent` is the
only writer. It is called by the actions that already embody each act:

- invitation sent, resent (token rotated), revoked, accepted, declined, which closes the
  `TODOS.md` invitation-history entry;
- member removed, rank changed, ownership transferred;
- checkout started, subscription cancelled, resumed;
- entitlement refresh requested by an operator; webhook event replayed.

**Written by intent, not by model events.** A model observer records that a column
changed. It cannot record that an administrator removed somebody, or who asked. It also
fires on the backfills and projections D31 depends on, and would record every role sync
as a person's act.

**It lives in the product, not the console.** The acts it records happen in the product.
Removing the console must not stop them being recorded, and a customer-facing audit
screen is the obvious next consumer.

**Attribution under impersonation.** While an impersonation is active, `RecordAuditEvent`
stamps its id, so "Alice removed Bob" reads "Alice (impersonated by operator Carol)
removed Bob". An audit trail that attributed an operator's act to the customer would be
worse than no trail.

**Where the actor comes from.** Seven of the audited actions receive no actor
(`RemoveOrganizationMember::handle(Membership)` is typical), and reading `Auth` or the
session inside an action records a null actor in every job and command. So the actor
travels the way the tenant does (D24). `App\Audit\AuditActor` lives in hidden
`Illuminate\Log\Context`:

- a `web` middleware, after `ResolveTenantContext`, sets it from the authenticated user
  and the live impersonation;
- console commands and webhook processing set it explicitly, with a null actor and a
  named `source` (`console`, `stripe`);
- registration sets it for exactly one call. `CreateNewUser` accepts a pending
  invitation before Fortify logs the new user in, so the middleware saw a guest.
  `CreateNewUser` wraps `ConsumePendingInvitation` in an `AuditActor` scope naming the new
  user, then restores the previous value;
- it is dehydrated into every queued job, and hydration **forgets** it when absent, so a
  worker never attributes one job's act to the previous job's actor;
- `RecordAuditEvent` reads it and nothing else. No action signature changes.

**Deleting an organization deletes its audit events** (`cascadeOnDelete`). This matches
D25 and D34: deletion means deletion. Keeping a tenant's history after the tenant is
gone would be a new data-retention promise with no decision behind it.

### D50 — An impersonation is a record with an expiry; starting it is the console's, ending it is the product's

`impersonations`: `operator_id`, `user_id`, `reason`, `started_at`, `expires_at`
(30 minutes), `ended_at`, `ended_by` (`operator`, `expiry`, `logout`, `revoked`). A platform table
with no `organization_id`, because a user belongs to several organizations and an
impersonation moves between them.

- **Starting** is a console action. `App\Actions\StartImpersonation` refuses self, any
  operator, and an unverified user. It requires a reason, **invalidates** the session
  (every attribute flushed, id rotated), writes back only an allowlist
  (`impersonation_id`, `impersonator_id`), and logs in as the user without remembering.
  The organization key is gone with everything else, so `ResolveTenantContext` picks the
  user's default. `regenerate()` is not enough: it keeps every attribute, including the
  operator's `auth.password_confirmed_at`, which would pass the user's
  `password.confirm` gates.
- **Ending** is a product action: a banner in the Inertia layout, fed by a shared prop,
  posting to `impersonation.destroy`. `App\Actions\EndImpersonation` invalidates the
  session the same way, restores the operator, and records who ended it. Nothing from the
  user's session carries back into the console.
- **`EnsureImpersonationIsLive`**, in the `web` group, ends an expired impersonation
  on the next request. It also ends one whose operator has been revoked
  (`ended_by = revoked`), logging the browser out rather than restoring anyone. While the
  `operators` table is absent after removal, it counts nobody as an operator.
  `operators:revoke` ends the operator's live rows itself. The guard refuses a set of
  routes while an impersonation is live. The refused routes
  are password, email, two-factor and passkey changes, account deletion, every billing
  mutation, and accepting or declining an invitation. The list is a named constant, and a
  test enumerates routes by name to prove each one returns 403.

**Why the split.** D4 forbids Inertia in the console and Livewire in the product. The
banner is product UI, so it is Vue. It has to live in the product whether or not the
console exists. Once the console is removed, no impersonation row can be created, and
the banner, the guard and the stop route are inert rather than dead. At7 asserts that
the stop route refuses without a live row.

**Why not `lab404/laravel-impersonate`.** It stores no reason and no expiry. It has no
route refusal list, and it knows nothing about the session tenant. The parts this needs
are the parts it lacks, and the part it has is about thirty lines.

### D51 — Replay applies a recorded event through the same pipeline, without the listener that needs a live request

A replay is offered for `unplaceable` and `errored` events only. A `superseded` event is
not replayable by construction: D42's watermark would refuse it again, and offering a
button that cannot work is dead navigation.

**The trap.** The package's `QueueRefreshFromWebhook` listener re-verifies the Stripe
signature against `app('request')` and requires its JSON body to equal the payload. A
replay runs inside a Livewire request, so the listener throws `unverified_webhook`. It
does so _after_ Cashier has already written the subscription row.

**The mechanism.** The event-applying half of `StripeWebhookController` moves into
`App\Billing\ApplyStripeEvent`, which resolves the organization, checks the watermark,
and calls the Cashier handler for the event type. HTTP delivery calls it and then
dispatches `WebhookHandled` as before. `App\Actions\ReplayWebhookEvent` calls the same
service and then asks `RefreshManager::request($organization, $eventId)` directly,
instead of dispatching an event whose listener assumes HTTP.

**Skipping the listener must not skip its checks.** Only the signature check is tied to
HTTP. The rest are not, so replay repeats them before requesting a refresh: the same five
event types (`customer.subscription.created|updated|deleted`,
`invoice.payment_succeeded|failed`), `livemode` equal to
`cashier-entitlements.live_mode`, `account` equal to `provider_context`, and exactly one
owner for the customer. Any other type is applied without a refresh. That is why a
replayed `customer.deleted`, whose Cashier handler clears `stripe_id`, never asks for a
refresh that would fail. Every replay ends in a named outcome: `replayed`, `superseded`
(the watermark refused it again), `unplaceable` (still no organization), `refused`
(`livemode` or `account` mismatch), or `errored` (the handler threw). An audit event is
written for every outcome. A test pins the five-type list against the package's
listener, so an upgrade that changes it fails loudly.

**Cost accepted:** Cashier's handler methods are protected, so `ApplyStripeEvent` reaches
them through the controller subclass that D32 already owns. That deepens the
upgrade-reading cost D32 accepted, in the same file.

### D52 — Removability is a CI job, not a claim

`scripts/remove-admin-console.php` is a chisel script. It deletes `app/Filament/`,
`app/Providers/Filament/`, the `operators` migration and model, the console commands,
`tests/Feature/AdminConsole/`, and the published Filament assets. It removes
`FilamentUser` and `canAccessPanel()` from `User`, and the provider line from
`bootstrap/providers.php`. Then it runs `composer remove filament/filament`.

A CI job, `admin-removed`, runs it on a clean checkout, then `composer ci:check`,
`php artisan wayfinder:generate`, `bun run types:check`, `bun run build`, and the browser
smoke test.

**"No dead navigation", made testable.** The product never hard-codes a console link.
The console's service provider shares an `adminConsoleUrl` Inertia prop for operators
only, and the sidebar renders the link when the prop is present. The sidebar's other
links are compiled from Wayfinder imports (`AppSidebar.vue:25-49`) and never appear in
Inertia props, so no PHP test can see them. Three checks, each able to fail:

1. `tests/Feature/AdminConsoleLinkTest.php` (outside `AdminConsole/`, so it survives
   removal): while the console is installed, the prop reaches operators and nobody else;
   once removed, it reaches nobody.
2. `wayfinder:generate` → `types:check` → `build`, in both jobs. A Vue file still
   importing a removed route fails to compile.
3. `tests/Browser/NavigationSmokeTest.php`: as a member and as an operator, click every
   sidebar and user-menu link. Assert no 404 or 500 and no console errors. It runs in the
   browser job and in `admin-removed`, and catches a hard-coded href or a runtime error.
   Hard-coded hrefs are exactly what the build cannot see.

**Why the frontend build is in the job.** Wayfinder generates TypeScript for every route.
A Vue file that imported a console route would pass every PHP test after removal, and
then fail to compile.

## Architecture

### Where each console screen gets its data

```
                         Fortify login (unchanged) ──▶ session: user, organization
                                                      │
  /admin page load ─── panel middleware ──────────────┤ no `web` group → no tenant
  /livewire/update ─── `web` group ── ResolveTenantContext resolves the OPERATOR's org
                           │
                           ▼
            ForgetTenantContext  (persistent panel middleware, D46)
                           │   tenant forgotten on BOTH paths
                           ▼
            canAccessPanel(): operators row · verified · 2FA outside local (D47)
                           │
       ┌───────────────────┼─────────────────────────────┬──────────────────────────┐
       ▼                   ▼                             ▼                          ▼
 OrganizationResource   UserResource            WebhookEventResource      ImpersonationResource
 (organizations —       (users — not            (webhook_events —          (impersonations —
  not tenant-owned)      tenant-owned)           platform, D48)             platform, D50)
       │                   │
       │ View page         └─ organizations: MembershipRepository::organizationsFor() (D22 door)
       ▼
 app/Operations/* (read only) ── every call: TenantContext::runFor($organization) ──▶ plain data
       ├── InspectEntitlements   ResolveAllowance + NativeStateStore::state() + usage + RefreshReceipts
       ├── SubscriptionTimeline  BillingFacts + webhook_events for stripe_customer_id + watermark
       └── OrganizationActivity  memberships, pending invitations, audit_events (paginated)
 app/Actions/* (writes, core) ── RequestEntitlementRefresh · ReplayWebhookEvent ──▶ audit event
       │
       ▼
 Filament Table::records() / Infolist  (custom data — no Eloquent query on tenant-owned models)
```

### "The webhook event that set it", precisely

```
Stripe ──▶ POST /stripe/webhook
             │
             ├─ webhook_events upsert (stripe_event_id) ─────────────── outcome recorded (D48)
             ├─ ApplyStripeEvent: runFor(org) · watermark · Cashier handler
             └─ WebhookHandled ─▶ QueueRefreshFromWebhook
                                    └─ NativeStateStore::request(owner, at, eventId)
                                         ├─ cashier_entitlement_receipts (event_id, received_at = at)
                                         └─ states.requested_at = at, requested_sequence++
                                                   │
                                     RefreshOwner ─┴─▶ complete(): completed_sequence, observed_at
```

Receipts and the state's `requested_at` are both whole seconds from the same `$at`, and
receipts carry no sequence. At checkout, `customer.subscription.created` and
`invoice.payment_succeeded` both request a refresh, usually inside one second. So the
inspector does not pretend there is one cause:

- When `completed_sequence == requested_sequence`, it takes **every** receipt whose
  `received_at` equals `requested_at`, joins them to `webhook_events` by
  `stripe_event_id`, and orders them by `stripe_created_at`.
- The subscription-shaped event with `applied_at` set is **primary**. The rest are listed as "also
  in this refresh". A receipt with no `webhook_events` row is shown by event id only.
- No matching receipt: the last refresh came from a sweep, a recovery or an operator,
  and the inspector says so. It does not guess an event.
- A refresh still pending: the inspector says that instead.

### Impersonation lifecycle

```
            operator on UserResource ── "Impersonate" (reason required)
                         │
                         ▼
   StartImpersonation ── refuses: self · operator · unverified ──▶ notification, no row
                         │
                         ▼  row(started_at, expires_at = +30m) · session invalidated
                         │  session: ONLY impersonation_id, impersonator_id (allowlist)
                         ▼
   product (Inertia) ─── banner: "Acting as Alice for Carol. Ends 14:32. [End]"
                         │
   every web request ─── EnsureImpersonationIsLive
                         ├─ expired ──▶ EndImpersonation(expiry) ──▶ back to operator
                         ├─ operator revoked ──▶ EndImpersonation(revoked) ──▶ logged out
                         ├─ refused route ──▶ 403
                         └─ otherwise ──▶ continue; RecordAuditEvent stamps impersonation_id
                         │
   [End] or logout ───── EndImpersonation(operator | logout) ──▶ operator restored, session invalidated
```

## Scope

### In scope

1. `filament/filament:^5.8` (Livewire 4, verified against `illuminate/contracts ^13.0`).
   Panel at `/admin`, no login page, `ForgetTenantContext` persistent (D46).
2. `operators` table, model, `operators:grant` / `operators:revoke`, `canAccessPanel()` (D47).
3. `webhook_events` table and model, written on every controller path; migrate and drop
   `failed_webhook_events`; `model:prune` for `WebhookEvent` scheduled daily (D48).
4. `ApplyStripeEvent` extracted from `StripeWebhookController` with no behaviour change,
   proven by the existing webhook tests before anything else moves (D51).
5. `audit_events`, `AuditEvent`, `RecordAuditEvent`, `AuditActor` (hidden Context, middleware, hydration hook, console/webhook setters), wired into the eleven acts listed in D49.
6. `impersonations`, `StartImpersonation`, `EndImpersonation`, `EnsureImpersonationIsLive`,
   the banner, and the shared prop (D50).
7. `app/Operations/` (reads only): `InspectEntitlements`, `SubscriptionTimeline`,
   `OrganizationActivity`. `app/Actions/` (writes, core, survive removal):
   `RequestEntitlementRefresh`, `ReplayWebhookEvent`.
8. Filament: organization lookup and view (entitlements, usage, subscription timeline,
   members, audit log); user resource; webhook event resource with replay;
   impersonation resource (read-only history).
9. `adminConsoleUrl` shared prop and conditional sidebar link (D52).
10. `scripts/remove-admin-console.php`, the `admin-removed` CI job (with Playwright), `AdminConsoleLinkTest`, `NavigationSmokeTest` (D52).
11. `CONTEXT.md` additions (vocabulary table above); D46–D52 into `0001-architecture-decisions.md`;
    build plan status.
12. Tests, per the coverage diagram below.

### NOT in scope

- **Override grant and revoke.** The package's ledger exists and `overrides` is `false`.
  Turning it on costs a query per resolve, and it is not on the journey. It is the most
  likely _next_ console action, so the inspector's "source" field already has an
  `override` case that renders when the ledger is enabled. `TODOS.md`.
- **Suspend, archive, restore.** Reads already honour `OrganizationStatus`. The write
  path is a billing question: a suspended organization Stripe keeps charging is D34's
  defect again. `TODOS.md`.
- **Syncing the Stripe customer on ownership transfer.** `TODOS.md` asks for it "before
  M6 puts a customer lookup in front of a support person". The console shows the
  organization's owner from `owner_id` and never Stripe's email, so the lookup cannot
  mislead. The sync stays a separate change. `TODOS.md` entry updated to say why.
- **`subscription_items.organization_id`.** The console reads items only through
  `BillingFacts`, per organization, inside `runFor()`. Nothing queries items directly, so
  the entry stays deferred with its trigger unchanged.
- **A cross-organization audit feed.** It would need the third door D46 refuses.
- **Payload redaction in `webhook_events`.** See D48's cost.
- **Customer-facing audit screen.** D49 makes it cheap later; not on the journey.
- **Filament theming.** Stock panel.

## Tests

### Coverage diagram

```
CODE PATHS                                               USER FLOWS
[+] D46 tenant discipline                                [+] Journey, last clause
  ├── page load: no tenant ────── [★★★ planned] At1        └── [→E2E] operator finds org → entitlement,
  ├── Livewire update: forgotten  [★★★ planned] At1             usage, "set by evt_…" — Ab1
  ├── operator's own org hidden ─ [★★★ planned] At1
  └── forgotten runFor raises ─── [★★  planned] At1      [+] Organization lookup
                                                           ├── [★★★ planned] by name / slug / cus_ — At3
[+] D47 operator access                                    └── [★★★ planned] by member email (D22 door) — At3
  ├── no row → 403 ────────────── [★★★ planned] At2
  ├── unverified → 403 ────────── [★★★ planned] At2      [+] Entitlement inspector
  ├── no 2FA in production → 403  [★★★ planned] At2        ├── [★★★ planned] package source — At4
  ├── Fortify login redirect ──── [★★  planned] At2        ├── [★★★ planned] floor source (never refreshed) — At4
  └── grant/revoke commands ───── [★★  planned] At2        ├── [★★  planned] stale → floor, says stale — At4
                                                           ├── [★★★ planned] trigger event named — At4
  ├── [★★★ planned] two events in one second → both, subscription primary — At4
  ├── [★★  planned] receipt without webhook_events row → id only — At4
  ├── [★★  planned] RefreshReceipts sole package-table reader (arch) — At4
[+] D48 webhook events                                     ├── [★★  planned] sweep-triggered → "no event" — At4
  ├── applied recorded ────────── [★★★ planned] At5        └── [★★  planned] refresh pending — At4
  ├── superseded recorded ─────── [★★★ planned] At5
  ├── unplaceable recorded ────── [★★★ planned] At5      [+] Impersonation
  ├── errored then applied ────── [★★★ planned] At5        ├── [★★★ planned] start → acting as user — At7
  ├── redelivery increments ───── [★★  planned] At5        ├── [★★★ planned] refuses self / operator / unverified — At7
  ├── migration from failed_* ─── [★★★ planned] At5
  ├── fold: duplicate ids merge ─ [★★★ planned] At5
  ├── fold: null ids kept apart ─ [★★  planned] At5
  ├── applied_at survives superseded redelivery [★★★ planned] At5
  ├── replayed/refused pruned at 180d ─ [★★  planned] At5        ├── [★★★ planned] expiry ends on next request — At7
  └── prune windows ───────────── [★★  planned] At5        ├── [★★★ planned] every refused route → 403 — At7
                                                           ├── [★★★ planned] end restores operator — At7
                                                           ├── [★★★ planned] session holds only allowlist; password.confirm demands confirmation — At7
[+] D49 audit events                                       ├── [★★  planned] tenant cleared on start — At7
  ├── [★★★ planned] /admin refused while impersonating — At7
  ├── [★★★ planned] operator revoked mid-session → next request logged out — At7
  ├── each of 11 acts writes ──── [★★★ planned] At6        └── [→E2E] banner visible, End works — Ab2
  ├── impersonation stamped ───── [★★★ planned] At6
  ├── actor over queue roundtrip ─ [★★★ planned] At6
  ├── command → source=console ── [★★  planned] At6
  ├── absent on next job forgets ─ [★★★ planned] At6
  ├── register via invitation → actor = new user [★★★ planned] At6
  ├── update/delete refused ───── [★★★ planned] At6      [+] Replay
  ├── org delete cascades ─────── [★★  planned] At6        ├── [★★★ planned] unplaceable → replayed — At8
  └── A's events invisible to B ─ [★★★ planned] At6        ├── [★★★ planned] refresh requested, no listener — At8
                                                           ├── [★★★ planned] superseded offers no action — At8
                                                           ├── [★★★ planned] customer.deleted → applied, no refresh — At8
                                                           ├── [★★★ planned] livemode/account mismatch → refused — At8
                                                           ├── [★★★ planned] handler throws → errored — At8
                                                           ├── [★★  planned] five-type list pinned to package — At8
[+] D51 ApplyStripeEvent extraction                        └── [★★  planned] still unplaceable → stays — At8
  ├── every existing webhook test green, byte-unchanged across T1 [★★★ existing]
  └── status + body per controller path ─ [★★★ planned] WebhookResponseContractTest (before T1)
                                                         [+] Removability (D52)
                                                           ├── [★★★ planned] console link: operators only / nobody after removal — At9
                                                           ├── [→E2E] every sidebar + user-menu link, member + operator — Ab3
                                                           ├── [→E2E] same, module removed (CI) — Ab3
                                                           ├── [★★★ planned] types:check after removal (CI) — At9
                                                           └── [★★★ planned] bun run build after removal (CI) — At9

COVERAGE: 1/57 today (the existing webhook suite), 57/57 planned: 52 feature, 4 E2E, 1 existing
QUALITY (planned): ★★★ 39 · ★★ 13 · E2E 4  |  CI-only: 3 (module-removed build, types, smoke)
Legend: ★★★ behaviour + edge + error | ★★ happy path | [→E2E] browser test
```

### Test files

| File                                                            | Proves                                                               |
| --------------------------------------------------------------- | -------------------------------------------------------------------- |
| `tests/Feature/AdminConsole/TenantDisciplineTest.php` (At1)     | D46 on both request paths, with the operator a member elsewhere      |
| `tests/Feature/AdminConsole/OperatorAccessTest.php` (At2)       | D47 access ladder, commands, Fortify login reuse                     |
| `tests/Feature/AdminConsole/OrganizationLookupTest.php` (At3)   | Lookup by each key, through the audited door only                    |
| `tests/Feature/AdminConsole/EntitlementInspectorTest.php` (At4) | Source, staleness, trigger event, pending, sweep-triggered           |
| `tests/Feature/Billing/WebhookEventLogTest.php` (At5)           | D48 every outcome, redelivery, migration, pruning                    |
| `tests/Feature/Billing/WebhookResponseContractTest.php`         | R4: status and body per controller path, pinned before T1            |
| `tests/Feature/Audit/AuditEventTest.php` (At6)                  | D49 every act, attribution, immutability, boundary                   |
| `tests/Feature/Impersonation/ImpersonationTest.php` (At7)       | D50 lifecycle, refusals, refused-route enumeration                   |
| `tests/Feature/AdminConsole/WebhookReplayTest.php` (At8)        | D51, including the listener trap                                     |
| `tests/Feature/AdminConsoleLinkTest.php` (At9)                  | D52 prop both states; outside `AdminConsole/` so it survives removal |
| `tests/Browser/NavigationSmokeTest.php` (Ab3)                   | Every navigation link clicks through, member and operator, both jobs |
| `tests/Committed/AdminJourneyTest.php` (Ab1)                    | The journey's last clause in a real browser                          |
| `tests/Browser/ImpersonationBannerTest.php` (Ab2)               | Banner renders, End returns the operator to the console              |

### Test traps this suite will hit

1. **Livewire tests hit `/livewire/update` through the `web` group**, so an operator with
   a session organization gets it resolved. At1 must assert on that path explicitly. A
   page-load-only test passes while the bug is live.
2. **`TenantQueryGuard` fails any console test that queries a tenant-owned table outside
   `runFor()`.** That is the point. It is not a reason to add `allowUnscoped()`.
3. **The package listener verifies the request signature.** A replay test that fires
   `WebhookHandled` fails with `unverified_webhook`, and one that fakes events hides the
   D51 trap. At8 asserts the refresh request exists and that no `WebhookHandled` was
   dispatched.
4. **Dropping `failed_webhook_events` breaks every test and fixture that names it**,
   including M4 and M5 webhook tests. Update them in the same commit as the migration.
5. **Filament's `FilamentUser` check applies only outside `local`.** At2 must run the
   production branch with `app()->detectEnvironment()` the way `ArchTest` already does, or
   the 2FA rule is never exercised.
6. **`RecordAuditEvent` inside an action's transaction** rolls back with the act it
   records, and that is the desired behaviour. An audit event for an act that did not
   happen is worse than none. Do not move it after commit.
7. **Wayfinder output is gitignored** (prior learning, 9/10). The banner's stop route
   import needs `wayfinder:generate` in its verify step, and the `admin-removed` job
   regenerates before building.

## Failure modes

| Codepath                | Realistic production failure                                 | Test | Handled          | Operator or user sees                             |
| ----------------------- | ------------------------------------------------------------ | ---- | ---------------- | ------------------------------------------------- |
| Console Livewire action | Service forgot `runFor()`; operator's own org resolved       | At1  | Yes (forgotten)  | `TenantContextMissing` error, never wrong data    |
| `canAccessPanel`        | Operator without 2FA in production                           | At2  | Yes              | 403 with a message naming two-factor              |
| Webhook controller      | Handler throws after the event row was written               | At5  | Yes              | Row `errored`; Stripe retries; retry upserts      |
| Webhook controller      | Writing `webhook_events` itself fails                        | At5  | **Propagates**   | 500; Stripe retries. Losing the record is worse   |
| Inspector               | Refresh pending or failing                                   | At4  | Yes              | "Refresh pending since …" / `last_error`          |
| Inspector               | Last refresh came from a sweep                               | At4  | Yes              | "Refreshed by schedule; no event"                 |
| Replay                  | Listener trap (`unverified_webhook`)                         | At8  | Yes (bypassed)   | Replay succeeds; refresh requested                |
| Replay                  | Organization still missing                                   | At8  | Yes              | Row stays `unplaceable`; notification says why    |
| Impersonation           | Operator leaves a tab open past expiry                       | At7  | Yes              | Next request ends it and returns to the console   |
| Impersonation           | Operator tries to change the user's password                 | At7  | Yes              | 403 naming the impersonation                      |
| Impersonation           | Operator's own account deleted mid-impersonation             | At7  | Yes              | `EndImpersonation` logs out rather than restoring |
| Removal                 | Vue file imports a console route                             | At9  | Yes (CI build)   | Nothing; the `admin-removed` job fails first      |
| Removal                 | Hard-coded `/admin` href in a Vue file                       | Ab3  | Yes (smoke)      | Nothing; the smoke test fails in `admin-removed`  |
| Impersonation           | Operator revoked mid-impersonation                           | At7  | Yes              | Next request logs the browser out                 |
| Impersonation           | Operator's password confirmation carried into user's session | At7  | Yes (invalidate) | `password.confirm` asks again                     |
| Replay                  | `livemode` / account mismatch                                | At8  | Yes              | Outcome `refused`, notification names the reason  |
| Webhook log             | Applied event redelivered after a newer one                  | At5  | Yes              | `applied_at` kept; last delivery `superseded`     |
| Audit                   | Invitation accepted during registration (guest request)      | At6  | Yes              | Actor is the new user, not blank                  |

**Critical gaps: none.** Every row above has a test and handling, and none fails silently.

## Parallelization

| Step                                                           | Modules touched                                                              | Depends on |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------- | ---------- |
| S1 — `ApplyStripeEvent` extraction, no behaviour change        | `app/Billing/`, `app/Http/Controllers/Billing/`                              | —          |
| S2 — `webhook_events` + fold `failed_webhook_events`           | `app/Models/`, `database/migrations/`, controller, tests                     | S1         |
| S3 — `audit_events` + `RecordAuditEvent` + wiring              | `app/Actions/`, `app/Models/`, `database/migrations/`                        | —          |
| S4 — impersonation core (actions, guard, banner, prop)         | `app/Actions/`, `app/Http/Middleware/`, `bootstrap/app.php`, `resources/js/` | S3         |
| S5 — Filament install, panel, operators, `ForgetTenantContext` | `app/Providers/Filament/`, `app/Models/`, `app/Console/`, `User`             | —          |
| S6 — `app/Operations/` reads + the two write Actions           | `app/Operations/`, `app/Actions/`                                            | S2, S3     |
| S7 — console screens                                           | `app/Filament/`                                                              | S4, S5, S6 |
| S8 — removal script, CI job, link test, navigation smoke       | `scripts/`, `.github/workflows/`, `tests/Feature/`, `tests/Browser/`         | S7         |

```
Lane A: T0 → S1 → S2 ─┐
Lane B: S3 → S4 ─┼─▶ S6 → S7 → S8
Lane C: S5 ──────┘
```

**Conflict flags:** S2 and S4 both touch `bootstrap/` wiring and shared test fixtures.
S3 and S4 both add actions that call `RecordAuditEvent`. Keep S3 and S4 in one lane.
`bootstrap/app.php` owns the web middleware order (D28), so S4's guard is appended
**after** `ResolveTenantContext`, and a test locks that position.

## Implementation tasks

- [x] **T0 (P1, human: ~2h / CC: ~10min)** — tests — `WebhookResponseContractTest`: status and body for every controller path, written against today's code and kept
    - Surfaced by: Test review — regression contract R4
    - Files: `tests/Feature/Billing/WebhookResponseContractTest.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing/WebhookResponseContractTest.php` green on `main` before T1
- [x] **T1 (P1, human: ~3h / CC: ~15min)** — billing — extract `ApplyStripeEvent`, no behaviour change. Own commit
    - Files: `app/Billing/ApplyStripeEvent.php`, `app/Http/Controllers/Billing/StripeWebhookController.php`
    - Verify: `vendor/bin/pest tests/Feature/Billing` green, and `git diff --stat HEAD~1 -- tests/Feature/Billing/StripeWebhookTest.php tests/Feature/Billing/WebhookEventAgeTest.php` empty
- [x] **T2 (P1, human: ~1d / CC: ~30min)** — billing — `webhook_events`, every outcome, fold and drop `failed_webhook_events`, prune command
    - Files: migrations, `app/Models/WebhookEvent.php`, controller, `routes/console.php`, fixtures
    - Test diff limited to the 10 storage assertions (3 reason reads → `outcome`; 6 "nothing retained" → recorded outcome; bad signature unchanged) plus new redelivery and fold assertions. Landed: 5 of the 6 became "only `applied` rows"; the sixth, the 500 path, became "one `errored` row", because D48 records errored deliveries too
    - Verify: `vendor/bin/pest tests/Feature/Billing`
- [x] **T3 (P1, human: ~1d / CC: ~40min)** — audit — `audit_events`, `RecordAuditEvent`, eleven call sites
    - Files: migration, `app/Models/AuditEvent.php`, `app/Actions/RecordAuditEvent.php`, `app/Audit/AuditActor.php`, actor middleware, `app/Providers/TenancyServiceProvider.php` (hydration), `bootstrap/app.php`, eleven actions
    - Verify: `vendor/bin/pest tests/Feature/Audit tests/Feature/Invitations tests/Feature/Organizations`
    - Landed: `AuditActor` lives only in hidden Context, so no hydration hook was needed. `Context::hydrate()` flushes before loading a job's payload, which forgets an actor the job did not carry. An act with no actor records `source = system`. The middleware binds the user only: the impersonation id joins it in T4, when impersonations exist. `ApplyStripeEvent` runs as `stripe`. Console acts are named through `CommandStarting`.

- [x] **T4 (P1, human: ~1.5d / CC: ~45min)** — impersonation — actions, guard, refused-route list, banner, shared prop
    - Files: migration, `app/Models/Impersonation.php`, `app/Actions/{Start,End}Impersonation.php`, `app/Http/Middleware/EnsureImpersonationIsLive.php`, `bootstrap/app.php`, `HandleInertiaRequests`, layout component
    - Verify: `php artisan wayfinder:generate --with-form && vendor/bin/pest tests/Feature/Impersonation`
    - Landed: the product cannot see the console's `operators` table, so it asks an `App\Contracts\Operators` contract ("is this user an operator", "where does an operator return to"). Its default, `NoOperators`, says nobody. T5 binds the real one. Once the console is removed, nothing can start an impersonation, and a live one ends as revoked on its next request.
    - Landed: the guard intercepts `logout` while impersonating and signs out this device only. Fortify's logout calls `cycleRememberToken()`, which would have ended the customer's own remembered sessions everywhere.
    - Landed: the guard runs before `ResolveTenantContext`, and the expiry test locks that: after expiry the operator's own organization resolves. `operators:revoke` closing live rows is T5's, with the command.
- [x] **T5 (P1, human: ~1d / CC: ~30min)** — console — Filament install, panel, operators, access ladder, `ForgetTenantContext`
    - Files: `composer.json`, `app/Providers/Filament/AdminConsoleServiceProvider.php`, `app/Models/Operator.php`, commands, `User`
    - Verify: `vendor/bin/pest tests/Feature/AdminConsole/OperatorAccessTest.php tests/Feature/AdminConsole/TenantDisciplineTest.php`
    - Landed: the provider is `AdminConsoleServiceProvider`, because the `laravel` arch preset requires the `ServiceProvider` suffix. `filament:install` rewrote `bootstrap/providers.php` and dropped the production exclusion of the billing replay provider. That was restored by hand, and the removal script (T8) must edit that file rather than regenerate it. The installer also added `filament:upgrade` to `post-autoload-dump` and three `public/*/filament` lines to `.gitignore`. The removal script undoes all three.
    - Landed: `Authenticate` is persistent as well as `ForgetTenantContext`, so revoking an operator stops the actions on a page already open, not only the next page. Grant and revoke are Actions (`GrantOperator`, `RevokeOperator`) behind thin commands, and revoke closes live impersonations.
    - Deferred to T7: At1's behavioural half (a real Livewire action over tenant data with the operator a member elsewhere) needs a console screen. T5 asserts the persistent registration.
- [x] **T6 (P1, human: ~1.5d / CC: ~45min)** — operations — three read services and two write Actions, each inside `runFor()`
    - Files: `app/Operations/{InspectEntitlements,SubscriptionTimeline,OrganizationActivity}.php`, `app/Entitlements/RefreshReceipts.php`, `app/Actions/{RequestEntitlementRefresh,ReplayWebhookEvent}.php`, `tests/Unit/ArchTest.php`
    - Verify: `vendor/bin/pest tests/Feature/AdminConsole`
    - Landed: the paged reads return `{rows, total}` rather than a `LengthAwarePaginator`. The console table builds its own paginator. The services stay plain data (D46), and the paginator's generic type is not worth fighting.
    - Landed: Cashier's handlers are reached through `App\Billing\CashierEventHandlers`, a never-routed subclass of Cashier's controller, because the `laravel` arch preset allows only resource methods on routed controllers. `StripeWebhookController` is unchanged by T6.
    - Landed: `ResolveAllowance::explain()` returns the answer together with its source (package or floor), so the inspector's label comes from the same code as the answer.
    - Landed: after `customer.deleted`, the customer lookup already finds no organization. A separate test with an `invoice.created` replay pins the type filter, which a mutation run showed was untested otherwise.
- [ ] **T7 (P1, human: ~2d / CC: ~1h)** — console — lookup, organization view, user, webhook events + replay, impersonation history
    - Files: `app/Filament/**`
    - Verify: `vendor/bin/pest tests/Feature/AdminConsole`
- [ ] **T8 (P1, human: ~1d / CC: ~40min)** — removability — chisel script, `admin-removed` CI job (Playwright), `AdminConsoleLinkTest`, `NavigationSmokeTest`, `adminConsoleUrl`
    - Surfaced by: Test review — R5, a PHP test cannot see Wayfinder-compiled hrefs
    - Files: `scripts/remove-admin-console.php`, `.github/workflows/tests.yml`, `tests/Feature/AdminConsoleLinkTest.php`, `tests/Browser/NavigationSmokeTest.php`, sidebar
    - Verify: run the script on a throwaway worktree, then `composer ci:check && bun run build`
- [ ] **T9 (P2, human: ~4h / CC: ~20min)** — journey — `AdminJourneyTest` and `ImpersonationBannerTest`
    - Verify: `composer test:browser` and the committed lane
- [ ] **T10 (P2, human: ~2h / CC: ~10min)** — docs — `CONTEXT.md` terms, D46–D52, build plan status, `TODOS.md` entries closed and added

## Inline diagrams the implementation should carry

- `app/Providers/Filament/AdminConsoleServiceProvider.php`: the two request paths and why
  `ForgetTenantContext` is persistent. Without it, a reader will delete the middleware as
  redundant.
- `app/Operations/InspectEntitlements.php`: the receipt-to-state matching rule, and the
  three cases where no event is named.
- `app/Http/Middleware/EnsureImpersonationIsLive.php`: the lifecycle diagram above.
- `app/Billing/ApplyStripeEvent.php`: why replay does not dispatch `WebhookHandled`.

**Constraint from `.ai/rules/general.md`:** these comments state the reason in plain
words. They never cite a decision number or a document ("see D46" is not a comment).

## Review findings

### Step 0 — Scope Challenge

Complexity gate tripped: about 40 files and 15 new classes. The scope record is S0 in the
ledger below.

Prior learnings applied: `saas-foundation-bindings-before-tenant` (10/10, 2026-09-17),
`wayfinder-actions-are-gitignored` (9/10), `dunning-replay-wraps-everything-in-a-transaction`
(9/10), `cashier-webhook-two-customer-id-shapes` (9/10).

### Section 1 — Architecture

1. **[P1] (confidence: 9/10) D50, `StartImpersonation`: the operator's session state
   survives into the impersonated session.** The plan says "regenerates the session".
   `vendor/laravel/framework/src/Illuminate/Session/Store.php:618-622`:
   `public function regenerate($destroy = false) { return tap($this->migrate($destroy), ...`
   `migrate()` changes the id and keeps every attribute. The operator's
   `auth.password_confirmed_at` (written at `Store.php:835`, read at
   `RequirePassword.php:96`) therefore carries over, and the impersonated session passes
   every `password.confirm` gate as the **user**. Decision R1.
2. **[P2] (confidence: 9/10) Factual correction, no behaviour change: `Doctor::report()`
   is global.** `Doctor.php:33` takes `(DateTimeImmutable $at, ?string $scope, ?int
$staleAfter)` and counts owners in `state()`. It has no per-owner argument. The table
   row and the diagram are corrected: per-organization refresh health comes from
   `NativeStateStore::state()`, and Doctor feeds the console home's health widget.
3. **[P3] (confidence: 9/10) Factual correction: test trap 6 named project creation**,
   which D49 does not audit. Reworded to cover any action's transaction.
4. **[P1] (confidence: 9/10) D49: seven of the audited acts have no actor to record.**
   D49 stamps `actor_id` and `impersonation_id`, but the actions it wires into do not
   receive an actor:
   `app/Actions/RemoveOrganizationMember.php:24` `public function handle(Membership $membership): void`,
   `ChangeOrganizationMemberRole.php:28` `handle(Membership $membership, MembershipRole $role)`,
   `CancelSubscription.php:16` / `ResumeSubscription.php:16` `handle(Organization $organization)`,
   `DeclineOrganizationInvitation.php:21`, `ResendOrganizationInvitation.php:32`,
   `TransferOrganizationOwnership.php:30`. Reading `Auth::user()` or the session inside
   an action breaks the Actions rule ("called from jobs, commands, HTTP"): in a job or
   command it silently records a null actor. Decision R2.
5. **[P2] (confidence: 8/10) "The webhook event that set it" is ambiguous at checkout,
   the exact moment the journey inspects.** The draft matches the receipt whose
   `received_at` equals the state's `requested_at`. Both are whole seconds from one `$at`:
   `NativeStateStore.php` `'received_at' => $at->getTimestamp()` and
   `'requested_at' => $at->getTimestamp()`. The receipts table has no ordering column:
   `$table->char('id', 64)->primary(); ... $table->bigInteger('received_at');`
   (`2026_09_21_102000_create_cashier_entitlements_tables.php:33-36`), and `id` is a
   sha-256. Checkout emits `customer.subscription.created` and
   `invoice.payment_succeeded` together, and the listener requests a refresh for both
   (`QueueRefreshFromWebhook.php`, the five-type list). Two receipts in one second match
   equally. Decision R3.

Section 1 dispositions: #1 → R1 approved (A). #2, #3 → corrected in place. #4 → R2
approved (A). #5 → R3 approved (A). The Livewire `web` group trap is already defended in
D46 and asserted by At1. No new finding.

### Section 2 — Code quality

1. **[P2] (confidence: 9/10) Necessary implementation of accepted scope: folding
   `failed_webhook_events` cannot be a straight copy.** The source table allows
   duplicates: `$table->string('stripe_event_id')->nullable()->index();`
   (`2026_09_20_090000_create_failed_webhook_events_table.php`), and
   `StripeWebhookController::keep()` calls `FailedWebhookEvent::query()->create([...])`
   once per delivery. A redelivered unplaceable event already has two rows.
   `webhook_events.stripe_event_id` is unique (D48), so a naive copy fails the migration
   on any real database. The fold therefore groups by `stripe_event_id`: `deliveries` is
   the count, `first_received_at`/`last_received_at` are the min/max, and the payload and
   reason come from the latest row. Rows with a null id are copied one by one, since a
   nullable unique column admits many nulls on all three engines. This is how S0's
   accepted "migrate and drop" gets implemented, not a new choice. D48 and T2 are amended
   and At5 gains the duplicate and null-id cases.
2. **[P2] (confidence: 9/10) Project rule: code comments do not cite decision numbers.**
   `.ai/rules/general.md`: "Explain the reason directly instead of citing internal
   decision numbers or documentation files." The "Inline diagrams" section asks for
   comments explaining D46, D50 and D51 behaviour. Those comments must state the reason
   itself ("Livewire updates run the `web` group, which resolves the operator's own
   organization"), never "see D46". Added to that section as a constraint.
3. **[P3] (confidence: 8/10) Missing edge, required proof of approved behaviour:** while
   impersonating, `/admin` must refuse, because the session belongs to a non-operator.
   Nested impersonation is then impossible by construction, and At7 asserts it.
4. **Shared-code rubric:** no extraction recommended. The three Operations each wrap one
   `runFor()` call and map to arrays. A shared base would couple three unrelated read
   shapes to save about six lines. The `webhook_events` upsert already has one natural
   home (`keep()` becomes `record()`, one private method), so no helper is needed.

Section 2 dispositions: all four are corrections or required proof of accepted scope.
No new choices.

### Section 3 — Tests

1. **[P1 CRITICAL, regression] (confidence: 9/10) T1 and T2 rewrite the webhook path
   that fourteen existing tests protect.** T1 moves event application out of
   `StripeWebhookController` (D51), and T2 replaces `failed_webhook_events` (D48). At risk:
   `tests/Feature/Billing/StripeWebhookTest.php:41-142` (seven tests: tenant from
   `customer` and from `id`, unplaceable retained with 200, tenant failures retained,
   other failures let Stripe retry, customerless events forwarded, bad signature
   rejected) and `tests/Feature/Billing/WebhookEventAgeTest.php:28-143` (seven tests:
   older skipped, newer applied, equal applied, missing `created` applied,
   delete-before-create, per-subscription tracking, non-subscription events unguarded).
   Ten storage assertions name the old table. Three read a retained row's `reason`
   (`StripeWebhookTest.php:95,114`, `WebhookEventAgeTest.php:41`). Six assert
   `assertDatabaseEmpty('failed_webhook_events')` on paths that **apply** (`:129,139`;
   `WebhookEventAgeTest.php:67,81,98,155`); those change meaning, because applied events
   are now recorded. One asserts that a bad signature records nothing (`:152`), which
   stays true: the signature middleware rejects before the controller runs. Decision R4,
   per the regression rule.
2. **[P1] (confidence: 9/10) D52's `NavigationTest` cannot see the navigation it claims
   to check.** The sidebar is static Vue with Wayfinder imports:
   `resources/js/components/AppSidebar.vue:25-28` `import { dashboard } from '@/routes';`
   `import { index as billing } from '@/routes/organizations/billing'; ...` and
   `:34-49` `href: dashboard(), ... href: billing(),`. An Inertia response is a component
   name plus props, and these hrefs are not props. So a PHP feature test that "renders
   each Inertia page and asserts every `href` in the shared navigation" has nothing to
   assert on. It would pass while a dead link ships. "Walks every named `GET` route" also
   cannot fill parameterised routes (`invitations/{token}`). Decision R5.
3. **Remaining coverage audit: no further gaps.** Every code path in D46–D52 and every
   user flow in the coverage diagram has a planned test. The four E2E paths are the
   journey clause, the impersonation banner, and navigation with and without the console.
   Those are auth, cross-service, and removal flows where a mock would hide the failure.
   No LLM or prompt surface, so there is no eval scope.

Section 3 dispositions: #1 → R4 approved (A). #2 → R5 approved (A). #3 → none needed.
Test plan artifact written to
`~/.gstack/projects/impruthvi-saas-foundation/impruthvi-feat-m5-entitlements-eng-review-test-plan-*.md`.

### Section 4 — Performance

No choices, and nothing beyond V1 scale. Four required implementation details are
recorded so they are not rediscovered:

1. **[P2] (confidence: 9/10) `ShouldBeStrict` is on everywhere** (`config/essentials.php`,
   and trap 7 of M5). Every Eloquent-backed Filament resource (`organizations` → `owner`,
   `impersonations` → `operator`/`user`, `operators` → `user`) must eager-load its shown
   relations in `getEloquentQuery()`. Otherwise the tests throw on lazy loading, which is
   the correct failure.
2. **[P2] (confidence: 8/10) Indexes the new tables need from day one:**
   `webhook_events (stripe_customer_id, stripe_created_at)` for the per-organization
   timeline, `(outcome, last_received_at)` for the prune and the "needs replay" filter;
   `audit_events (organization_id, occurred_at)`; `impersonations (user_id, ended_at)`
   and `(operator_id, started_at)`.
3. **[P3] (confidence: 8/10) Custom-data tables paginate in the service**, receiving
   Filament's `$page` and `$recordsPerPage` and returning a `LengthAwarePaginator`. Never
   load a whole audit log to slice it in PHP.
4. **[P3] (confidence: 7/10) `adminConsoleUrl` costs one indexed lookup per Inertia
   request** (`operators.user_id` unique). It is shared as a lazy closure and only for an
   authenticated user. That is acceptable, and not cached, because a revoked operator must
   lose the link on the next request.

Section 4 dispositions: all four are implementation details of accepted scope. No new
choices.

### Outside voice — Codex (completed, `codex exec`, read-only)

Six findings. All six were verified against source by the parent reviewer before being
put to the user as O1–O6:

1. **[P1]** Revoking an operator does not end their live impersonations (D50 checks only
   expiry). O1.
2. **[P1]** Replay skips more than signature checks: the listener's type list, `livemode`,
   provider account and customer uniqueness (`QueueRefreshFromWebhook.php:24-40`).
   Cashier's `handleCustomerDeleted` (`WebhookController.php:255`) clears `stripe_id`, so
   an unconditional refresh after it fails. O2.
3. **[P2]** Registration-time invitation acceptance has no audit actor:
   `CreateNewUser.php:58` `$this->pendingInvitations->handle(session()->driver(), $user);`
   runs before Fortify logs the user in, so the R2 middleware saw a guest. O3.
4. **[P2]** R3 needs receipts, but `NativeStateStore::state()` (`:174`) returns only the
   state row, and the plan forbids direct package-table reads. O4.
5. **[P2]** One mutable `outcome` loses history: when A is applied, B advances the
   watermark, and A is redelivered, A becomes `superseded` and its `applied` is
   overwritten (`StripeWebhookController.php:176`). O5.
6. **[P2]** `replayed` rows fall in neither prune window, so their payloads are kept
   forever, which contradicts D48's 180-day ceiling. O6.

Codex recommendation: "revise before implementation because impersonation revocation,
replay validation, and historical evidence remain underspecified, and the inspector
depends on an unavailable read API."

## Decision ledger

### S0: Scope and structure (Step 0 complexity gate)

Feature answers: D2 → A (user reply "A", after the gstack upgrade). All six build-plan
surfaces. Overrides, suspend/archive, Stripe owner sync and `subscription_items.organization_id`
are deferred to `TODOS.md`.
Structure: D3 → B, "Smaller arrangement" (user reply "Go with your recommendation").
`app/Operations/` holds reads only: `InspectEntitlements`, `SubscriptionTimeline`,
`OrganizationActivity`. `RequestEntitlementRefresh` and `ReplayWebhookEvent` are core
Actions in `app/Actions/`.
Accepted scope: the draft's In-scope list with the D3 arrangement applied.
Pending remedies: none at the time of the scope answer.
State: approved

### R1: What the impersonated session inherits from the operator's session

Finding: Section 1 #1, P1, confidence 9/10, `Store.php:618-622` + plan D50 "regenerates the session", reviewer: eng review (Claude).
Plan baseline: D50 as drafted: `session()->regenerate()`, then write the impersonation keys and clear the organization key. Not approved; original proposal.
Runtime evidence: `regenerate()` → `migrate()` keeps all attributes. `auth.password_confirmed_at` survives (`Store.php:835`, `RequirePassword.php:96`). Fortify's two-factor keys and any `url.intended` survive too. Verified by reading the framework source.
Comparison grid:

| Choice                           | Current (draft)                          | A                                                                                                                      | B                                                                                   |
| -------------------------------- | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| R1 session contents at start/end | regenerate: operator's keys carried over | invalidate, then write only the allowlisted keys (`impersonation_id`, `impersonator_id`)                               | keep regenerate, forget a denylist (`auth.password_confirmed_at`, organization key) |
| Proof                            | none                                     | At7: after start, `password.confirm` routes redirect to confirmation; session holds only allowlisted keys; same on end | At7: denylisted keys absent                                                         |

Question D4:
D4 — What does an impersonated session keep from the operator's session?
Project: M6 admin console plan, D50 `StartImpersonation` / `EndImpersonation`.
ELI10: When an operator becomes Alice, Laravel keeps everything already stored in the operator's session. That includes "this person typed their password a minute ago". So the operator, acting as Alice, gets past every "confirm your password" screen of Alice's account. The fix decides what the new session is allowed to carry.
Stakes if we pick wrong: an operator silently passes Alice's password-confirmation gates. Or, with a denylist, the next session key someone adds leaks the same way.
Recommendation: A, because an allowlist fails closed. A key nobody thought of is dropped, not leaked.
Completeness: A=10/10, B=6/10
Pros / cons:
A) Invalidate, then allowlist (recommended)
✅ `invalidate()` flushes every attribute and rotates the id. Only the two impersonation keys are written back, so nothing the operator confirmed survives.
✅ The same rule runs at the end, so Alice's state never leaks back into the operator's console session either.
❌ Flash messages and `url.intended` are lost across the switch. The console notification has to be re-flashed after invalidation, a two-line cost.
B) Regenerate, then forget a denylist
✅ Smallest diff: one `forget([...])` call after the existing regenerate.
❌ Fails open. Any future key (a new Fortify flag, a package's "recently verified" marker) carries over silently, and no test notices.
❌ Fortify's two-factor challenge keys and `url.intended` still carry unless someone lists them too.
Net: A costs two lines and closes the whole class. B closes today's instance and leaves the next one open.
Header: Impersonation session
Options:
A) Invalidate, then allowlist (recommended)
`invalidate()` on start and on end, then write back only `impersonation_id` and `impersonator_id`. Test At7 asserts the session holds only those keys and that `password.confirm` routes demand confirmation. Human ~1h / CC ~5min. Low maintenance.
B) Regenerate, then forget a denylist
Keep `regenerate()` and `forget()` a named list of keys. Test At7 asserts those keys are absent. Human ~30min / CC ~3min. Every new session key needs a list review.

State: approved
Actual answer: A) Invalidate, then allowlist (user reply "Go with your suggestion", to D4)
Accepted scope: `invalidate()` on start and on end of an impersonation; write back only `impersonation_id` and `impersonator_id`; re-flash the console notification after invalidation. At7 asserts the session holds only those keys after start and after end, and that `password.confirm` routes demand confirmation while impersonating. D50 text, lifecycle diagram and coverage diagram amended.
History: none

### R2: Where an audit event gets its actor and impersonation from

Finding: Section 1 #4, P1, confidence 9/10, `RemoveOrganizationMember.php:24` and six other `handle()` signatures + plan D49 "stamps its id", reviewer: eng review (Claude).
Plan baseline: D49 as drafted. `actor_id` and `impersonation_id` are recorded, with no mechanism named. Not approved; original proposal.
Runtime evidence: 7 of the 11 audited actions take no actor (signatures quoted in finding 4). `TenantContext` already mirrors the tenant into `Illuminate\Log\Context` (`TenantContext.php:61`), and `TenancyServiceProvider.php:25` restores it on hydration. That precedent (D24) carries a value into queued jobs and forgets it when absent.
Comparison grid:

| Choice                        | Current (draft) | A                                                                                                                                                                                                                                                                                               | B                                                                                                                         | C                                                       |
| ----------------------------- | --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| R2 actor/impersonation source | unspecified     | `AuditActor` in hidden `Context`: set by a `web` middleware from the authenticated user and the session's impersonation, set explicitly by console commands and webhook processing (actor null, `source` named); read by `RecordAuditEvent`; dehydrated into queued jobs, forgotten when absent | explicit `?User $actor` and `?Impersonation $impersonation` parameters added to every audited `handle()` and every caller | `RecordAuditEvent` reads `Auth::user()` and the session |
| Works in jobs/commands        | —               | yes, carried or explicitly named                                                                                                                                                                                                                                                                | yes, if every caller passes it                                                                                            | no, null actor silently                                 |
| Proof                         | —               | At6: actor and impersonation recorded over HTTP, across a real queue roundtrip, and as `source=console` from a command; absent context on a later job forgets                                                                                                                                   | At6: each call site passes the actor                                                                                      | At6 over HTTP only                                      |

Question D5:
D5 — Where does an audit event learn who did it, and whether an operator was impersonating?
Project: M6 admin console plan, D49 `RecordAuditEvent`, wired into eleven existing actions.
ELI10: Every audit line has to say who did it, and "Carol, as Alice" when an operator is impersonating. But most of the actions being audited are never told who is acting. `RemoveOrganizationMember` only receives the membership. Something has to carry "who" to the audit writer, including when the work runs later in a queued job or from a console command.
Stakes if we pick wrong: audit lines with a blank actor for anything done in the background or from the console. Or an operator's act recorded as the customer's, the one failure D49 exists to prevent.
Recommendation: A, because it copies the mechanism D24 already proved for the tenant. It reaches queued jobs for free, and it touches no action signatures.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Carry it in Context, like the tenant (recommended)
✅ Same shape as `TenantContext`: set once per request, dehydrated into every job, and forgotten when absent, so a worker never attributes one job's actor to the next.
✅ No signature changes across seven actions and their callers and tests. The audit call is one line per action.
❌ Ambient rather than explicit. A reader of `RemoveOrganizationMember` cannot see who the actor is from its signature, so the middleware and the command wrapper need the inline comment.
B) Pass the actor explicitly
✅ Most explicit: every `handle()` says who acts, and a missing actor is a type error rather than a null row.
❌ Changes seven signatures and every controller, job, command and test that calls them. That is roughly 30 call sites for one audit column.
❌ The impersonation still has to come from the request, so B needs an ambient source anyway for half of the attribution.
C) Read Auth and the session inside RecordAuditEvent
✅ Smallest change: no middleware, no parameters.
❌ Silent null actor in every queued job and console command, and the Actions rule says actions run in exactly those places.
Net: A reuses a proven house pattern and stays correct off the request path. B is the most explicit but pays ~30 call-site changes and still needs A's mechanism for impersonation.
Header: Audit attribution
Options:
A) Carry it in Context, like the tenant (recommended)
`App\Audit\AuditActor` held in hidden `Illuminate\Log\Context`: a `web` middleware after `ResolveTenantContext` sets it from the user and the live impersonation. Console commands and webhook processing set it with a null actor and a named source. Hydration forgets it when absent. `RecordAuditEvent` reads it. At6 proves it over HTTP, over a real queue roundtrip, and from a command. Human ~4h / CC ~20min. Maintenance: one middleware, one provider hook.
B) Pass the actor explicitly
Add `?User $actor` and `?Impersonation $impersonation` to seven `handle()` signatures and update every caller and test. The impersonation is still read from the request by the controllers. Human ~1.5d / CC ~45min. Maintenance: every new audited action repeats the parameters.
C) Read Auth and the session inside RecordAuditEvent
`RecordAuditEvent` calls `Auth::user()` and `session('impersonation_id')`. Human ~1h / CC ~5min. Records a null actor off the request path, with no error.

State: approved
Actual answer: A) Carry it in Context, like the tenant (user reply "Go with your suggestion", to D5)
Accepted scope: `App\Audit\AuditActor` in hidden `Illuminate\Log\Context`; `web` middleware after `ResolveTenantContext` sets it from user + live impersonation; console commands and webhook processing set null actor with named source; hydration forgets when absent; `RecordAuditEvent` reads only it; no action signature changes. At6 proves HTTP, real queue roundtrip, command source, and forget-on-absent. D49 text, scope item 5, coverage diagram and T3 amended.
History: none

### R3: How the inspector names the event behind the current entitlement

Finding: Section 1 #5, P2, confidence 8/10, `NativeStateStore.php` `request()` + receipts migration lines 33-36 + plan "The inspector names the receipt whose `received_at` equals…", reviewer: eng review (Claude).
Plan baseline: draft rule: exactly one receipt matched on `received_at == requested_at`. Not approved; original proposal.
Runtime evidence: second-resolution timestamps, hash ids, no sequence column on receipts. Checkout produces two refresh-requesting events, usually within one second. Stripe's own `created` for each is in `webhook_events.stripe_created_at` (D48).
Comparison grid:

| Choice                | Current (draft)                            | A                                                                                                                                                                                             | B                                                                                                                                                          | C                                                                                                         |
| --------------------- | ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| R3 matching rule      | one receipt, `received_at == requested_at` | every receipt in that second, joined to `webhook_events` and ordered by Stripe `created`. Name the subscription-shaped `applied` one as primary and list the others as "also in this refresh" | ignore receipts; name the newest `applied` subscription-shaped webhook event for the organization whose `last_received_at` ≤ the state's `last_success_at` | upstream: `cashier-entitlements` 0.3.0 adds a `requested_sequence` column to receipts, then match exactly |
| Honest when ambiguous | no (picks arbitrarily)                     | yes, shows all candidates                                                                                                                                                                     | partly (can name an event whose refresh failed)                                                                                                            | yes, exact                                                                                                |
| Proof                 | —                                          | At4: two events in one second → both shown, `subscription.created` primary; sweep-triggered → "no event"; pending → "pending"; receipt with no `webhook_events` row → shown by event id only  | At4: newest applied named                                                                                                                                  | package test + At4                                                                                        |

Question D6:
D6 — How does the entitlement inspector name "the webhook event that set it"?
Project: M6 admin console plan, D48 + `InspectEntitlements`, the last clause of the D8 journey.
ELI10: The inspector links the current entitlement to the Stripe event that caused it. The package only remembers which second each event asked for a refresh. At checkout, Stripe sends two events in the same second, "subscription created" and "payment succeeded", and both ask. The draft picks one as if there could only be one. That is the exact screen the README demo shows.
Stakes if we pick wrong: the demo names a random event half the time. Or it names an event whose refresh actually failed, which a support person then trusts.
Recommendation: A, because it stays exact about what the package knows and says so when two events share a second. It needs no upstream release.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Show every event in that second (recommended)
✅ Never claims more than the data proves. Both checkout events appear, and the subscription event, which wrote the plan, is marked primary.
✅ Uses `webhook_events` (D48) for type, outcome and Stripe's own `created`, so ordering inside the second is real rather than guessed.
❌ The screen has to render a small list rather than one line, and the journey test asserts on the primary row rather than on a single value.
B) Newest applied event before the last successful refresh
✅ Always one answer, and no receipt matching at all. Simplest to render and test.
❌ Can name an event whose own refresh failed, with a later sweep succeeding. The inspector then states a cause it cannot prove.
C) Fix it upstream first
✅ Exact by construction: the receipt records which request it produced.
❌ Blocks M6 on a `cashier-entitlements` release again (M5 already paid this once, D39), and existing receipts stay ambiguous anyway.
Net: A is exact and honest today at the cost of a short list. B is simpler but can mislead a support person. C is exact only for new data, and costs a release.
Header: Inspector event link
Options:
A) Show every event in that second (recommended)
Match all receipts with `received_at == requested_at` when `completed_sequence == requested_sequence`. Join to `webhook_events` by `stripe_event_id` and order by `stripe_created_at`. Primary = the subscription-shaped `applied` event; the rest are listed as "also in this refresh". Unchanged: sweep-triggered → "no event", pending → "pending". At4 covers two events in one second, the sweep and pending cases, and a receipt with no `webhook_events` row. Human ~3h / CC ~15min.
B) Newest applied event before the last successful refresh
Name the newest `applied` subscription-shaped `webhook_events` row with `last_received_at` ≤ `last_success_at`. Receipts are not read. At4 covers the newest-applied case. Human ~1.5h / CC ~10min. Can misattribute after a failed refresh.
C) Fix it upstream first
Release `cashier-entitlements` 0.3.0 with a `requested_sequence` on receipts, pin it, then match exactly. Rows from before the upgrade fall back to A's list. Human ~1d / CC ~40min plus a release. Blocks T6.

State: approved
Actual answer: A) Show every event in that second (user reply "With your recommendation", to D6)
Accepted scope: match all receipts with `received_at == requested_at` when `completed_sequence == requested_sequence`; join to `webhook_events` by `stripe_event_id`, order by `stripe_created_at`; subscription-shaped `applied` event primary, the rest "also in this refresh"; receipt without a `webhook_events` row shown by id; sweep → "no event", pending → "pending" unchanged. At4 covers each. Architecture diagram text, proof-of-done wording and coverage diagram amended.
History: none

### R4: Regression contract for the webhook path (T1 extraction + T2 storage swap)

Finding: Section 3 #1, P1 CRITICAL, confidence 9/10, `StripeWebhookTest.php:41-142` + `WebhookEventAgeTest.php:28-143`, reviewer: eng review (Claude). Regression rule: coverage is required; only its shape is decided here.
Plan baseline: T1 "no behaviour change, proven by the existing webhook tests before anything else moves"; trap 4 "update them in the same commit as the migration". The shape of the contract is not approved.
Runtime evidence: 14 existing tests cover the path. 10 assertions reference the old table: 3 reason reads, 6 "nothing retained" on applying paths, 1 bad signature (verified with grep, lines listed in finding #1). Bad signature returns 403 (`StripeWebhookTest.php:146` `assertForbidden()`).
Comparison grid:

| Choice              | Current (draft) | A                                                                                                                                                                                                                     | B                                                                        |
| ------------------- | --------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| Behaviour preserved | implied         | all 14 behaviours listed in finding #1, **plus** HTTP status and body per path (200 "Webhook retained." / "Webhook superseded.", 500 on other failures, 403 on bad signature)                                         | same 14 behaviours                                                       |
| Intentional changes | trap 4          | only: 3 reason reads → `WebhookEvent` `outcome` + `outcome_reason`; 6 "nothing retained" → "exactly one row, outcome `applied`"; bad-signature stays "nothing recorded"; redelivery increments instead of duplicating | same list                                                                |
| Sequencing          | T1 then T2      | two commits: **T1 lands with both test files byte-unchanged and green**; T2's diff to those files is limited to the 10 storage assertions, reviewed as such                                                           | one commit: extraction and storage swap together, tests updated together |
| Added proof         | —               | a characterization test pinning status + body for every controller path, written **before** T1 and kept                                                                                                               | none beyond existing                                                     |

Question D7:
D7 — How is the existing webhook behaviour protected while M6 rewrites that path?
Project: M6 admin console plan, T1 (`ApplyStripeEvent` extraction) and T2 (`webhook_events` replaces `failed_webhook_events`).
ELI10: Fourteen existing tests prove the Stripe webhook handles the right organization, keeps events it can't place, and never lets an old event overwrite a newer one. M6 moves that code and changes where it stores events. Ten test lines check the old storage table. Six of them say "nothing was stored", which stops being true once every event is stored. The question is how to keep that change small enough that a real regression cannot hide inside it.
Stakes if we pick wrong: a regression in which organization a payment is applied to, or in out-of-order protection, slips through inside a "tests updated for new table" commit. That is a silent wrong subscription on a real customer.
Recommendation: A, because separating "move the code" from "change the storage", with the tests byte-identical across the move, makes the extraction provably behaviour-free.
Completeness: A=10/10, B=7/10
Pros / cons:
A) Two steps, tests frozen across the move (recommended)
✅ T1's proof is mechanical: the same two test files (14 tests), untouched, pass before and after. No judgement needed.
✅ T2's test diff is only the 10 storage assertions, so a reviewer can see that nothing else about behaviour moved.
❌ A characterization test for status and body per path must be written first. Roughly 8 short cases, one more file to keep.
B) One step, tests updated together
✅ One commit, and less ceremony than two ordered steps.
❌ The extraction and the storage swap share one test diff, so an edited assertion could be hiding a behaviour change, and no one can tell which.
❌ Response bodies and statuses stay unpinned. Only side effects are asserted today.
Net: A costs one small characterization file and a commit boundary, and buys a provably behaviour-free extraction. B saves that and lets the riskiest diff in M6 carry its own test edits.
Header: Webhook regression contract
Options:
A) Two steps, tests frozen across the move (recommended)
Write `tests/Feature/Billing/WebhookResponseContractTest.php` first: status and body for every controller path. T1 lands with `StripeWebhookTest.php` and `WebhookEventAgeTest.php` byte-unchanged and green. T2 changes only the 10 storage assertions (3 reason reads → `outcome`; 6 "nothing retained" → one `applied` row; bad signature unchanged) and adds redelivery assertions. Human ~3h / CC ~15min. Maintenance: one extra file.
B) One step, tests updated together
T1 and T2 land as one change, with the existing tests edited alongside. No characterization file. Human ~1h / CC ~5min. The behaviour-free claim for the extraction rests on review, not on unchanged tests.

State: approved
Actual answer: A) Two steps, tests frozen across the move (user reply "With your recommendation", to D7)
Accepted scope: new T0 writes `WebhookResponseContractTest` (status + body per controller path) before T1 and keeps it; T1 is its own commit with `StripeWebhookTest.php` and `WebhookEventAgeTest.php` byte-unchanged and green; T2's diff to those files is limited to the 10 storage assertions (3 → `outcome`, 6 → one `applied` row, bad signature unchanged) plus new redelivery/fold assertions. Tasks, test table, coverage diagram and lane A amended.
History: none

### R5: How "no dead navigation" is proven, with and without the console

Finding: Section 3 #2, P1, confidence 9/10, `AppSidebar.vue:25-49` + plan D52 `NavigationTest`, reviewer: eng review (Claude).
Plan baseline: D52 as drafted: a PHP `NavigationTest` rendering Inertia pages and checking shared-navigation hrefs, run in both jobs. Removability itself is approved scope (S0); the proof mechanism is not.
Runtime evidence: navigation hrefs are compiled into Vue from Wayfinder imports and never appear in Inertia props. A browser suite exists (`composer test:browser`, CI job `browser (sqlite)`). `package.json` has `types:check` (`vue-tsc --noEmit`) and `build` (`vite build`).
Comparison grid:

| Choice                                       | Current (draft)                                        | A                                                                                                                                                                                                        | B                           |
| -------------------------------------------- | ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------- |
| Server-side proof                            | `NavigationTest` over Inertia props (cannot see hrefs) | `AdminConsoleLinkTest` (outside `AdminConsole/`): `adminConsoleUrl` shared for operators only while installed; absent for everyone after removal                                                         | same `AdminConsoleLinkTest` |
| Compile-time proof                           | `bun run build`                                        | `wayfinder:generate` → `bun run types:check` → `bun run build` in both jobs; a removed route's import fails                                                                                              | same                        |
| Runtime proof                                | none that works                                        | `tests/Browser/NavigationSmokeTest.php`: as a member and as an operator, click every sidebar and user-menu link; assert no 404/500 and no console errors; runs in the browser job and in `admin-removed` | none                        |
| Catches a hard-coded string href to `/admin` | no                                                     | yes (smoke)                                                                                                                                                                                              | no                          |

Question D8:
D8 — How do we prove removing the console leaves no dead links?
Project: M6 admin console plan, D52 removability, the M6 "proof it is done".
ELI10: The plan promised a PHP test that checks every navigation link. But the links live inside compiled Vue files, not in anything the PHP test can see, so that test would always pass. We need checks that can actually see links: the frontend build fails when a link points at a route that no longer exists, and a real browser can click every link.
Stakes if we pick wrong: M6's own definition of done, "no dead navigation", is asserted by a test that cannot fail. A developer who removes the console then finds the broken link in production.
Recommendation: A, because only a browser sees what a user clicks. The build catches imports but not a hard-coded `/admin` string or a runtime error.
Completeness: A=10/10, B=8/10
Pros / cons:
A) Link test + build checks + browser smoke (recommended)
✅ Three layers, each able to fail: the shared prop (PHP), removed routes (type-check and build), and real clicks (browser), for both a member and an operator.
✅ Reuses the existing browser suite and CI job. The smoke test is one short file that also runs in `admin-removed`.
❌ The `admin-removed` job needs Playwright and Chromium installed. That adds about 1-2 minutes of CI time on that job.
B) Link test + build checks only
✅ No browser in the removal job, so it stays fast and simple.
✅ Wayfinder routes are imports, so the build catches the likely failure, a removed route still imported.
❌ A hard-coded href or a runtime error on navigation passes silently.
Net: A buys a runtime check that can actually fail, for a couple of CI minutes. B relies on the build alone, which is strong for imports and blind to everything else.
Header: Dead-navigation proof
Options:
A) Link test + build checks + browser smoke (recommended)
Replace `NavigationTest` with `tests/Feature/AdminConsoleLinkTest.php` (prop present for operators only while installed; absent after removal), `wayfinder:generate` + `types:check` + `build` in both jobs, and `tests/Browser/NavigationSmokeTest.php` clicking every sidebar and user-menu link as a member and as an operator in the browser job and in `admin-removed`. Human ~4h / CC ~20min. CI +1-2 min on `admin-removed`.
B) Link test + build checks only
Replace `NavigationTest` with `AdminConsoleLinkTest` and `wayfinder:generate` + `types:check` + `build` in both jobs. No browser in `admin-removed`. Human ~2h / CC ~10min. Blind to hard-coded hrefs and runtime errors.

State: approved
Actual answer: A) Link test + build checks + browser smoke (user reply "With your recommendation", to D8)
Accepted scope: `NavigationTest` replaced by `tests/Feature/AdminConsoleLinkTest.php` (prop to operators only while installed, to nobody after removal), `wayfinder:generate` + `types:check` + `build` in both jobs, and `tests/Browser/NavigationSmokeTest.php` (every sidebar and user-menu link, member and operator, browser job and `admin-removed`, which gains Playwright). D52, D50 reference, scope item 10, coverage diagram, test table, S8, T8 and failure modes amended.
History: none

### O1: Does revoking an operator end their live impersonations?

Finding: Outside voice #1, P1, confidence 9/10 (verified: the D50 guard reads only `expires_at`), reviewer: Codex.
Plan baseline: R1-approved D50; `EnsureImpersonationIsLive` ends on expiry only. Operator authority is not rechecked.
Runtime evidence: no operator check exists after start; `canAccessPanel()` gates `/admin`, not the product routes the impersonated session uses.
Comparison grid:

| Choice                     | Current | A Apply                                                                                                                           | B Keep | C Investigate                                 | D Defer                |
| -------------------------- | ------- | --------------------------------------------------------------------------------------------------------------------------------- | ------ | --------------------------------------------- | ---------------------- |
| O1 guard rechecks operator | no      | yes: guard ends the impersonation (`ended_by = revoked`) when the `operators` row is gone; `operators:revoke` also ends live rows | no     | bounded 1h spike on cost/placement, no change | TODOS entry, no change |
| Proof                      | —       | At7: revoke mid-impersonation → next product request returns to login, row `ended_by = revoked`                                   | —      | —                                             | —                      |

Question D9:
D9 — Should revoking an operator end their impersonations immediately?
Project: M6 plan, D50 `EnsureImpersonationIsLive` and `operators:revoke`.
ELI10: Right now an impersonation lasts 30 minutes no matter what. If you revoke someone's operator access because you no longer trust them, they keep acting as a customer until the timer runs out. Codex found this, and I confirmed it: the guard only checks the clock.
Stakes if we pick wrong: a revoked operator keeps up to 30 minutes of customer access, able to remove members, after you took their access away.
Recommendation: A, because revocation that does not revoke is not revocation, and the check is one indexed lookup on a request that already reads the impersonation row.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Apply this change (recommended)
✅ Revocation takes effect on the very next request, and the command also closes the rows so the history says why.
✅ Costs one join on `operators` inside a guard that already loads the impersonation.
❌ The product-side guard now reads an `operators` table the console owns, so it has to tolerate that table being absent after removal.
B) Keep this row's current value
✅ No change. Expiry already bounds the window to 30 minutes.
❌ Up to 30 minutes of access after an explicit revocation, the exact case revocation exists for.
C) Investigate before choosing
✅ Time-boxed to 1h to confirm guard placement and removal behaviour.
❌ Nothing changes until another decision. The gap stays open in the plan.
D) Defer this proposed change only
✅ Keeps M6 moving. Recorded in TODOS.md with the exposure stated.
❌ Ships a known security gap in the milestone that introduces impersonation.
Net: A closes a real authority gap for one lookup. Everything else ships it knowingly.
Header: Revoke ends impersonation
Options:
A) Apply this change (recommended)
Guard ends any live impersonation whose operator row is missing (`ended_by = revoked`) and returns the browser to login. `operators:revoke` ends live rows too. When the table is absent after removal, the guard treats it as "no operators". At7 covers revoke mid-session. Human ~2h / CC ~10min.
B) Keep this row's current value
No change. Expiry alone bounds impersonation.
C) Investigate before choosing
1h bounded spike on guard placement and post-removal behaviour. No plan change until a follow-up decision.
D) Defer this proposed change only
Add a TODOS.md entry. No change in M6.

State: approved
Actual answer: A) Apply this change (user reply "With your recommendation", to D9)
Accepted scope: `EnsureImpersonationIsLive` ends any live impersonation whose operator row is missing (`ended_by = revoked`) and logs the browser out; `operators:revoke` ends live rows; absent table after removal = no operators. At7 covers revoke mid-session. D50 text, `ended_by` values, lifecycle diagram and coverage diagram amended.
History: none

### O2: What checks does a replay run before requesting a refresh?

Finding: Outside voice #2, P1, confidence 9/10 (verified `QueueRefreshFromWebhook.php:24-40`, `WebhookController.php:255`), reviewer: Codex.
Plan baseline: D51 as drafted: replay calls `RefreshManager::request($organization, $eventId)` unconditionally after applying.
Runtime evidence: the listener refuses types outside its five, refuses `livemode`/`account` mismatch, and requires exactly one customer. `handleCustomerDeleted` clears `stripe_id`, so a post-deletion refresh fails.
Comparison grid:

| Choice                | Current | A Apply                                                                                                                                                                                                                                                                                                                                                 | B Keep                | C Investigate | D Defer |
| --------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- | ------------- | ------- |
| O2 replay eligibility | none    | `ReplayWebhookEvent` runs the listener's non-HTTP checks (same five types, `livemode`, `account`, one owner) before requesting a refresh. Other types apply without a refresh. Outcomes defined: `replayed`, `superseded` (watermark refused), `unplaceable` (still), `refused` (context mismatch), with an `errored` handler failure staying `errored` | unconditional refresh | 1h spike      | TODOS   |
| Proof                 | —       | At8: each outcome; `customer.deleted` replay requests no refresh; livemode mismatch refused                                                                                                                                                                                                                                                             | —                     | —             | —       |

Question D10:
D10 — What must a replay check before it asks for an entitlement refresh?
Project: M6 plan, D51 `ReplayWebhookEvent`.
ELI10: A normal webhook passes through a package listener that checks the event is a type that affects entitlements, is live or test mode as expected, and belongs to exactly one customer. Replay skips that listener because it needs a live HTTP request. But skipping it also skipped those checks. Codex found this, and I confirmed it. Replaying a "customer deleted" event would then fail after the deletion had already been applied.
Stakes if we pick wrong: a replay requests refreshes for events that should not trigger one, crosses test and live mode, or fails halfway after changing data.
Recommendation: A, because replay must be the same pipeline minus only the HTTP part, and these checks are not HTTP.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Apply this change (recommended)
✅ Replay keeps every protection a live delivery has, except the signature, which cannot apply offline.
✅ Every replay ends in a named outcome, so the operator sees why nothing happened.
❌ The listener's checks are duplicated in app code, because the package keeps them private to the listener, and they can drift on a package upgrade. A test pins them.
B) Keep this row's current value
✅ Simplest replay.
❌ Unguarded refreshes and a half-applied `customer.deleted`.
C) Investigate before choosing
✅ Could explore an upstream seam that exposes the checks.
❌ No decision, and replay stays unsafe in the plan meanwhile.
D) Defer this proposed change only
✅ Could ship replay later.
❌ Leaves the plan's replay unsafe as written; it cannot ship as is.
Net: A is the minimum for replay to be safe. The cost is a pinned copy of four checks.
Header: Replay eligibility
Options:
A) Apply this change (recommended)
`ReplayWebhookEvent` mirrors the listener's non-HTTP checks before requesting a refresh; other types apply without one; outcomes `replayed` / `superseded` / `unplaceable` / `refused` / `errored`. At8 covers each, plus `customer.deleted` and a livemode mismatch. A test pins the five-type list against the package. Human ~3h / CC ~15min.
B) Keep this row's current value
Unconditional refresh after apply.
C) Investigate before choosing
1h spike on an upstream seam. No plan change yet.
D) Defer this proposed change only
TODOS entry; replay as drafted.

State: approved
Actual answer: A) Apply this change (user reply "With your recommendation", to D10)
Accepted scope: `ReplayWebhookEvent` repeats the listener's non-HTTP checks (five types, `livemode`, `account`, one owner) before requesting a refresh; other types are applied without a refresh; outcomes `replayed` / `superseded` / `unplaceable` / `refused` / `errored`, each audited; a test pins the five-type list. At8 covers each outcome, `customer.deleted` and a mismatch. D51 text, outcome enum and coverage diagram amended.
History: none

### O3: Who is the audit actor when an invitation is accepted during registration?

Finding: Outside voice #3, P2, confidence 9/10 (verified `CreateNewUser.php:58`), reviewer: Codex.
Plan baseline: R2-approved: `AuditActor` set by a `web` middleware from the authenticated user.
Runtime evidence: `CreateNewUser` calls `ConsumePendingInvitation` before Fortify logs the user in, so the middleware ran as a guest.
Comparison grid:

| Choice                   | Current    | A Apply                                                                                                     | B Keep                     | C Investigate | D Defer |
| ------------------------ | ---------- | ----------------------------------------------------------------------------------------------------------- | -------------------------- | ------------- | ------- |
| O3 actor at registration | null actor | `CreateNewUser` sets `AuditActor` to the new user, scoped around `ConsumePendingInvitation` (restore after) | null actor, `source = web` | spike         | TODOS   |
| Proof                    | —          | At6: register via an invitation link → the accept event's actor is the new user                             | —                          | —             | —       |

Question D11:
D11 — Who does the audit log say accepted an invitation during sign-up?
Project: M6 plan, R2 `AuditActor`, `CreateNewUser.php:58`.
ELI10: When a stranger signs up from an invitation link, the invitation is accepted in the middle of registration, before they count as logged in. The audit middleware saw a visitor with no account, so the "invitation accepted" line would have no actor. Codex found this, and I confirmed it in `CreateNewUser.php:58`.
Stakes if we pick wrong: the invitation audit trail, which D49 exists partly to provide, shows "someone" accepted the invitation on the M2 journey's main path.
Recommendation: A, because the actor is known at that exact line and the fix is a scoped assignment around one call.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Apply this change (recommended)
✅ The registration path records the right actor, and the scope restores afterwards so nothing leaks.
✅ A two-line change at the one place the actor is known but not yet authenticated.
❌ A second place that sets `AuditActor`, beside the middleware, the console and the webhook.
B) Keep this row's current value
✅ No change.
❌ Null actor on the most common invitation acceptance path.
C) Investigate before choosing
✅ Could look for other pre-login acts.
❌ Delays a known fix.
D) Defer this proposed change only
✅ M6 moves on.
❌ Ships blank actors on day one.
Net: A fixes the gap where it occurs for two lines. The others leave the main invitation path unattributed.
Header: Registration actor
Options:
A) Apply this change (recommended)
`CreateNewUser` wraps `ConsumePendingInvitation` in an `AuditActor` scope naming the new user, then restores. At6 covers register-via-invitation. Human ~1h / CC ~5min.
B) Keep this row's current value
Null actor with `source = web` for that event.
C) Investigate before choosing
1h audit of other pre-login acts. No plan change yet.
D) Defer this proposed change only
TODOS entry.

State: approved
Actual answer: A) Apply this change (user reply "With your recommendation", to D11)
Accepted scope: `CreateNewUser` wraps `ConsumePendingInvitation` in an `AuditActor` scope naming the new user, then restores; At6 covers register-via-invitation. D49 "Where the actor comes from" and coverage diagram amended.
History: none

### O4: How does the inspector read receipts?

Finding: Outside voice #4, P2, confidence 9/10 (verified `NativeStateStore.php:174-189`), reviewer: Codex.
Plan baseline: R3-approved matching rule; "What already exists" says package tables are read only through `state()` and `usage()`.
Runtime evidence: no public receipt reader. `NativeStateStore::ownerId(OwnerReference)` is public (`:27`) and yields the key receipts use.
Comparison grid:

| Choice            | Current                           | A Apply                                                                                                                                                                                                                  | B Keep                        | C Investigate            | D Defer                                      |
| ----------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------- | ------------------------ | -------------------------------------------- |
| O4 receipt access | forbidden (so R3 unimplementable) | one app class `App\Entitlements\RefreshReceipts` reads `cashier_entitlement_receipts` by `NativeStateStore::ownerId()` and `received_at`, read-only, and is the only direct package-table reader; an arch test pins that | keep the ban (R3 cannot ship) | spike on upstream reader | upstream `receipts()` API first, blocking T6 |
| Proof             | —                                 | At4 through the class; arch test                                                                                                                                                                                         | —                             | —                        | —                                            |

Question D12:
D12 — How does the inspector get the refresh receipts R3 needs?
Project: M6 plan, R3 matching rule + "What already exists" row on package tables.
ELI10: The plan you approved names the webhook events behind an entitlement by reading the package's refresh receipts. But the package has no public way to read receipts, and the plan also said we never read package tables directly. Both cannot be true. Codex found this, and I confirmed it.
Stakes if we pick wrong: the inspector's headline feature is unbuildable as written, or it becomes a scattered set of raw queries against a package's private tables.
Recommendation: A, because one named read-only class keeps the exception visible and testable, the way this repo handles its two audited tenant doors.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Apply this change (recommended)
✅ Unblocks R3 with no package release, using the package's own public `ownerId()` for the key.
✅ One class, pinned by an arch test as the only direct reader, so the exception cannot spread.
❌ Couples to a package table's shape. A package upgrade that changes receipts must be read, the same accepted cost as D32 and D28.
B) Keep this row's current value
✅ No coupling to package internals.
❌ R3, which you approved, cannot be built. The journey's last clause loses its event link.
C) Investigate before choosing
✅ Might find an existing seam.
❌ There is none today (checked). The inspector stays blocked.
D) Defer this proposed change only
✅ Clean upstream API.
❌ Blocks T6 on a package release, which M5 already paid once.
Net: A trades a documented, test-pinned coupling for an unblocked inspector. D is cleaner and costs a release cycle.
Header: Receipt reader
Options:
A) Apply this change (recommended)
Add `App\Entitlements\RefreshReceipts` (read-only; keyed by `NativeStateStore::ownerId()`; filters by `received_at`), the only direct reader of package tables, pinned by an arch test. "What already exists" is amended. Human ~2h / CC ~10min.
B) Keep this row's current value
Keep the ban; R3 cannot ship.
C) Investigate before choosing
1h upstream spike; no plan change.
D) Defer this proposed change only
Wait for a `cashier-entitlements` `receipts()` API; T6 is blocked.

State: approved
Actual answer: A) Apply this change (user reply "With your recommendation", to D12)
Accepted scope: `App\Entitlements\RefreshReceipts` reads `cashier_entitlement_receipts` read-only, keyed by `NativeStateStore::ownerId()`, filtered by `received_at`; sole direct package-table reader, pinned by an arch test; At4 goes through it. "What already exists", data-flow diagram, coverage diagram and T6 amended.
History: none

### O5: How does `webhook_events` keep "this event was applied" through later redeliveries?

Finding: Outside voice #5, P2, confidence 8/10 (verified: the upsert overwrites `outcome`; the watermark at `StripeWebhookController.php:176` marks a late redelivery superseded), reviewer: Codex.
Plan baseline: D48 as drafted: one mutable `outcome` per `stripe_event_id`.
Runtime evidence: A applied → B newer applied → A redelivered → superseded, which overwrites `applied`. R3 reads `applied` as evidence.
Comparison grid:

| Choice                 | Current            | A Apply                                                                                                                                   | B Keep              | C Investigate | D Defer |
| ---------------------- | ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------- | ------------- | ------- |
| O5 application history | lost on redelivery | add `applied_at` (set on first successful apply, never cleared); `outcome` stays "latest delivery". R3 and the timeline read `applied_at` | one mutable outcome | spike         | TODOS   |
| Proof                  | —                  | At5: apply A, apply newer B, redeliver A → `outcome = superseded`, `applied_at` kept                                                      | —                   | —             | —       |

Question D13:
D13 — How does the webhook log remember an event was applied if Stripe re-sends it later?
Project: M6 plan, D48 `webhook_events`.
ELI10: Each event has one "outcome" column. If event A was applied, then a newer event B arrived, and then Stripe re-sent A, the re-send is marked "superseded" and overwrites "applied". The log then says A never took effect, and the inspector (R3) trusts that column. Codex found this, and I confirmed the path.
Stakes if we pick wrong: the inspector and the timeline hide the event that actually set a customer's plan, the exact thing D8's journey shows.
Recommendation: A, because one extra timestamp keeps the fact without a second table.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Apply this change (recommended)
✅ The fact "this applied" becomes write-once, while "latest delivery outcome" stays accurate.
✅ One nullable column, with no history table.
❌ Two related fields to explain on the screen. The timeline labels them "applied at" and "last delivery".
B) Keep this row's current value
✅ Simplest schema.
❌ Loses evidence on a normal Stripe redelivery.
C) Investigate before choosing
✅ Could consider a delivery-history table.
❌ Codex and I both judge that unnecessary. The delay buys little.
D) Defer this proposed change only
✅ Ships sooner.
❌ R3 reads a field that can lie.
Net: A costs one column and keeps the inspector truthful.
Header: Applied history
Options:
A) Apply this change (recommended)
Add nullable `applied_at` to `webhook_events`, written once on first successful application and never cleared. `outcome` means the latest delivery. R3 and `SubscriptionTimeline` use `applied_at`. At5 covers the redelivery sequence. Human ~1h / CC ~5min.
B) Keep this row's current value
One mutable `outcome`.
C) Investigate before choosing
1h spike on a delivery-history table.
D) Defer this proposed change only
TODOS entry.

State: approved
Actual answer: A) Apply this change (user reply "For all question With your recommendation", covering D13)
Accepted scope: Nullable `applied_at` on `webhook_events`, written once on first successful application, never cleared; `outcome` = latest delivery; R3 and `SubscriptionTimeline` read `applied_at`. At5 covers apply A → apply B → redeliver A. D48 schema, R3 text and coverage diagram amended.
History: none

### O6: How long are `replayed` rows kept?

Finding: Outside voice #6, P2, confidence 9/10 (verified: D48's prune windows name `applied`, `superseded`, `unplaceable` and `errored` only), reviewer: Codex.
Plan baseline: D48 windows: 90 days for applied/superseded, 180 days for unplaceable/errored. `replayed` is unlisted, so it is kept forever.
Runtime evidence: the plan text only.
Comparison grid:

| Choice               | Current              | A Apply                                                          | B Keep  | C Investigate | D Defer |
| -------------------- | -------------------- | ---------------------------------------------------------------- | ------- | ------------- | ------- |
| O6 `replayed` window | forever (unintended) | 180 days from `last_received_at`, same as the class it came from | forever | —             | TODOS   |
| Proof                | —                    | At5 prune covers `replayed`                                      | —       | —             | —       |

Question D14:
D14 — How long does the webhook log keep an event that was replayed?
Project: M6 plan, D48 retention.
ELI10: The cleanup job deletes old webhook rows after 90 or 180 days, depending on outcome. "Replayed" was forgotten, so those rows, raw Stripe payloads with customer details, would be kept forever. That breaks the 180-day ceiling the plan itself set.
Stakes if we pick wrong: personal data kept indefinitely, contradicting the plan's own retention promise.
Recommendation: A, because a replayed row was an unplaceable or errored row, so it keeps that class's 180 days.
Note: options differ in kind, not coverage. No completeness score.
Pros / cons:
A) Apply this change (recommended)
✅ Honours the stated ceiling, with one enum case added to the prune query.
✅ Consistent: the row keeps the window of the problem it recovered from.
❌ None beyond the one line and its test.
B) Keep this row's current value
✅ Nothing to change.
❌ Unbounded retention of payloads, contrary to D48.
C) Investigate before choosing
✅ None meaningful. The choice is a window length.
❌ Delays a one-line fix.
D) Defer this proposed change only
✅ None meaningful.
❌ Ships the contradiction.
Net: A is a one-line consistency fix.
Header: Replayed retention
Options:
A) Apply this change (recommended)
Prune `replayed` rows 180 days after `last_received_at`. At5 covers it. Human ~15min / CC ~2min.
B) Keep this row's current value
Kept forever.
C) Investigate before choosing
No meaningful investigation; placeholder.
D) Defer this proposed change only
TODOS entry.

State: approved
Actual answer: A) Apply this change (user reply "For all question With your recommendation", covering D14)
Accepted scope: `replayed` (and `refused`) rows pruned 180 days after `last_received_at`. At5 covers it. D48 retention text and coverage diagram amended.
History: none

### TD1: TODO — grant and revoke overrides from the console

Proposal: new `TODOS.md` entry (What/Why/Pros/Cons/Context/Depends written).
Options: A) Add to TODOS.md (recommended) · B) Skip · C) Build it now in this PR.
State: approved
Actual answer: A) Add to TODOS.md (user standing instruction "For all question With your recommendation")
Accepted scope: entry added to `TODOS.md`.
History: none

### TD2: TODO — suspend, archive and restore an organization

Proposal: new `TODOS.md` entry.
Options: A) Add to TODOS.md (recommended) · B) Skip · C) Build it now in this PR.
State: approved
Actual answer: A) Add to TODOS.md (same standing instruction)
Accepted scope: entry added to `TODOS.md`.
History: none

### TD3: TODO — redact personal data in retained webhook payloads

Proposal: new `TODOS.md` entry.
Options: A) Add to TODOS.md (recommended) · B) Skip · C) Build it now in this PR.
State: approved
Actual answer: A) Add to TODOS.md (same standing instruction)
Accepted scope: entry added to `TODOS.md`.
History: none

Existing `TODOS.md` entries updated as consequences of approved scope, with no new choice:
invitation history (resolved by D49), failed-webhook retention (resolved by D48),
entitlement-state pruning (position recorded by D48), Stripe owner sync and
`subscription_items` (M6 notes added, still deferred).

Approval readiness: PASS. S0 (D2 "A", D3 "Go with your recommendation"), R1 (D4 "Go with
your suggestion"), R2 (D5 "Go with your suggestion"), R3 (D6 "With your recommendation"),
R4 (D7 "With your recommendation"), R5 (D8 "With your recommendation"), O1 (D9), O2
(D10), O3 (D11), O4 (D12), each "With your recommendation", and O5 (D13), O6 (D14),
TD1–TD3 under the user's standing instruction "For all question With your
recommendation". The R4 regression contract was settled by its own dedicated question
(D7). Factual corrections (Section 1 #2, #3) and required implementation (Section 2 #1–#3,
Section 4 #1–#4) change no approved behaviour.

## Unresolved decisions

None in this review.

## Suppressed findings (appendix)

None. No finding scored below 5, and none was suppressed.

## Completion summary

- Step 0: Scope Challenge — scope accepted as-is (A: all six surfaces), with the smaller
  file arrangement (B)
- Architecture Review: 5 issues found (3 decisions, 2 factual corrections)
- Code Quality Review: 3 issues found (all required implementation of accepted scope)
- Test Review: diagram produced, 2 gaps identified (regression contract, dead-navigation proof)
- Performance Review: 4 issues found (implementation details, no choices)
- NOT in scope: written
- What already exists: written
- TODOS.md updates: 3 items proposed to user, 3 added; 5 existing entries updated
- Failure modes: 0 critical gaps flagged
- Unresolved decisions: 0 in this review
- Outside voice: Codex, completed. 6 findings, all verified and all applied (O1–O6)
- Parallelization: 3 lanes, 3 parallel / then S6 → S7 → S8 sequential
- Lake Score: 3/4 (D4, D7, D8 chose the 10/10 option; D2 chose A at 9/10)

## GSTACK REVIEW REPORT

| Review         | Trigger               | Why                             | Runs | Status       | Findings                          |
| -------------- | --------------------- | ------------------------------- | ---- | ------------ | --------------------------------- |
| CEO Review     | `/plan-ceo-review`    | Scope & strategy                | 0    | —            | —                                 |
| Outside Review | Codex (`codex exec`)  | Independent 2nd opinion         | 1    | issues_found | 6 findings, 6 verified, 6 applied |
| Eng Review     | `/plan-eng-review`    | Architecture & tests (required) | 1    | ISSUES OPEN  | 14 issues, 0 critical gaps        |
| Design Review  | `/plan-design-review` | UI/UX gaps                      | 0    | —            | —                                 |
| DX Review      | `/plan-devex-review`  | Developer experience gaps       | 0    | —            | —                                 |

- **OUTSIDE COVERAGE:** Codex, plan-review phase, completed on `ea9d7b0`. Six findings:
  revocation, replay checks, the registration actor, the receipt reader, `applied_at` and
  replayed retention. All verified against source and approved as O1–O6.
- **CROSS-MODEL:** Codex and the eng review agree on D46's organization-first console,
  R2's Context transport ("R2's Context transport remains appropriate"), and on not
  building a delivery-history table. Codex found six gaps the eng review missed (O1–O6),
  two of them security (O1, O2). The eng review found five that Codex did not raise: R1,
  R2, R3, R4 and R5. No open disagreement.
- **VERDICT:** ENG REVIEWED. 14 issues mapped to approved work, 0 critical gaps, 0
  unresolved. Logged `issues_open` because findings exist, not because anything is
  undecided. Ready to implement; eng review required before ship.

NO UNRESOLVED DECISIONS
