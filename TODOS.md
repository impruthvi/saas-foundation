# TODOS

Work that was considered, deliberately deferred, and should not be rediscovered from
scratch. Each entry records why it was deferred and where to pick it up.

## Retain superseded invitation history

**What:** Keep a record of every invitation attempt for an address, not only the latest.

**Why:** M2 enforces one invitation row per `(organization_id, email)` and rotates that row
on resend or re-invite. The row therefore carries the current invitation, not the history.
The first time someone asks "who invited this person, and how many times did we try", the
answer is not in the database.

**Pros:** A real audit trail for the one action that grants access to a tenant. Makes
"audited" in the M2 milestone description literally true rather than approximately true.

**Cons:** A second table, or an event stream, for a question nobody has asked yet. Under
D11's scope filter that is exactly the kind of thing that gets deferred.

**Context:** M2 chose row rotation over inserting a second row because a partial unique
index (`… where status = 'pending'`) is unavailable on MySQL, and D3 keeps core migrations
portable. The alternative — enforcing uniqueness in the action instead of the index —
reintroduces the check-then-insert race that `App\Actions\CreateOrganization` was written
to avoid. Start at `app/Actions/InviteOrganizationMember.php` and
`app/Actions/ResendOrganizationInvitation.php`; both already know the moment a token is
rotated, which is where a history row would be written.

**Depends on / blocked by:** M6, which owns the audit log. Do not build a bespoke
invitation-history table ahead of it — write invitation events into the general audit log
once that exists.

## Count pending invitations against seats

**What:** Decide whether a pending invitation consumes a billed seat, and enforce it.

**Why:** Today an organization can issue unlimited invitations regardless of what its plan
allows. Once seats are billed, "invite ten people on a three-seat plan" has to resolve to
something other than ten memberships.

**Pros:** Closes the gap between what an organization can invite and what it is paying
for, before a customer finds it.

**Cons:** Requires a policy decision that is genuinely open — most products charge on
acceptance, some reserve on invitation — and that decision belongs with billing, not with
invitations.

**Context:** `CONTEXT.md` is explicit that a seat is a billed quantity and not a person, so
a pending invitation is deliberately not a seat in M2's vocabulary. The enforcement point
will be `app/Actions/InviteOrganizationMember.php`, asking the entitlements package the
same way M5 asks it about the `projects` limit. `impruthvi/cashier-entitlements` exposes
`LocalResolver::for($owner)->limit()` / `->usage()` / `->remaining()`.

**Depends on / blocked by:** M4 (subscriptions and quantities exist) and M5 (the pattern
for server-side limit enforcement, including the concurrency test). Do not attempt before
M5's limit enforcement is written — the invitation case should reuse it, not invent a
parallel one.

## Run the browser tests, and run them in CI

**What:** Get `tests/Browser` executing again, and put it in a test suite something runs.

**Why:** Two separate problems, both older than M3 and both found while wiring the
members screen. Playwright is outdated — `package.json` pins `playwright ^1.62.1` and the
plugin demands 1.63.0 — so every browser test errors with "Playwright is outdated",
including the `WelcomeTest` that has been in the repository since M0. And `phpunit.xml`
registers only the `Unit` and `Feature` suites, so `tests/Browser` is not collected by
`composer test` or by CI at all. That is why the suite reports green while those tests
fail when named directly.

**Pros:** `tests/Browser/MembersManagementTest.php` exists and is written; it asserts the
two things a feature test cannot reach, that the controls are on the page and that the
confirmation actually removes somebody. M7 makes the whole ten-minute journey one browser
test (D8), so this has to work before the milestone that depends on it entirely.

**Cons:** `bun add --dev playwright@latest && bunx playwright install` downloads browser
binaries, which is a real cost on CI and on a fresh clone, and it is the kind of thing
that should be a deliberate choice rather than a side effect of someone running a test.

**Context:** Start by running those two commands and then
`vendor/bin/pest tests/Browser`. Adding a `Browser` testsuite to `phpunit.xml` is one
block, but do it _after_ the binaries work — wiring a suite that immediately fails turns
a known gap into a red build. Consider whether browser tests belong in `composer test` at
all or in a separate `composer test:browser` that CI runs as its own job, since they are
slower than the rest of the suite combined.

**Depends on / blocked by:** Nothing. This is unblocked work that was deferred because
installing browser binaries is the user's call, not the agent's. **Now scheduled as M4
T1**, as its own commit ahead of any billing code: M4 returns an external redirect through
`Inertia::location()`, and only a browser can prove that click actually leaves the
application.

## Guard the session-held tenant on mutations outside billing

**What:** Make every tenant-scoped mutation name the organization it intends to act on,
and refuse a mismatch, the way D33 already makes billing mutations do it.

**Why:** D26 keeps the current organization in the session rather than in a URL segment,
and no mutation carries it in the request body. So a form rendered for organization A and
submitted after the session moved to B executes against B. Open the members screen for
Acme, switch to Globex in a second tab, return to the first tab and remove somebody: the
removal lands in Globex. The screen showed one organization and the endpoint used another.

**Pros:** Closes a whole class of wrong-tenant writes rather than the one instance M4
happened to need. Makes the user's intent explicit in the request instead of inferring it
from ambient session state, which is the same argument D33 makes for money.

**Cons:** Reopens D26 one milestone after it was decided, and touches every controller M1
through M3 shipped. It also puts a field in every form whose only job is to be compared
against something the server already knows, which reads as ceremony until you have seen it
fire.

**Context:** M4 scoped D33 deliberately to billing, because there the failure moves money
and everywhere else it moves an action. The mechanism is already written and can be lifted
directly: `App\Http\Requests\Billing\CheckoutRequest` compares the submitted organization
slug against `TenantContext::current()` and returns 409 with a message telling the user the
organization changed elsewhere. The affected endpoints are the member update and destroy
routes, the invitation store, delivery and destroy routes, and anything M5 adds that
writes. `app/Http/Middleware/ResolveTenantContext.php` is where the session key is read;
`SwitchOrganizationController` is where it is written.

**Depends on / blocked by:** M4, which proves the pattern on one screen first. Do not
generalize it before that — the 409 copy and the client-side reload behaviour want one
real screen's worth of use before they become a rule for every form in the product.

## Tell Stripe when an organization changes owner

**What:** Update the Stripe customer's email and name when ownership of an organization is
transferred.

**Why:** D35 has `Organization::stripeEmail()` read the owner's email, because
`organizations` has no email column of its own and a Stripe customer created without one
gets no receipts and is identifiable in the Stripe dashboard only by an integer. That read
happens once, when the customer is created. `TransferOrganizationOwnership` moves
`owner_id` and never tells Stripe, so from the first transfer onwards the Stripe customer
carries the previous owner's address. Receipts and any Stripe-side dunning mail go to
somebody who no longer owns the organization.

**Pros:** Keeps the billing contact true to the single ownership fact D23 established,
rather than to whatever it happened to be on the day the customer row was created. It is
also the cheapest moment to do it — the action already runs in a transaction and already
knows both users.

**Cons:** Puts a Stripe API call inside an action that is currently local and synchronous,
so it needs the same treatment D37 gave checkout: the network call outside the transaction,
and a failure that does not strand a half-finished transfer. That is more design than the
one-line fix it first appears to be.

**Context:** Start at `app/Actions/TransferOrganizationOwnership.php`. The likely shape is
a queued job dispatched after commit carrying the organization id, calling
`$organization->updateStripeCustomer([...])`, so a Stripe outage delays the sync rather
than failing the transfer. Note the queue trap this repository already recorded: a job
carrying a tenant-owned model trips the suite-wide query guard through
`SerializesModels`, so carry the id rather than the model. An organization with no
`stripe_id` yet must be a no-op, not an error.

**Depends on / blocked by:** M4, which is where `stripeEmail()` and the Stripe customer
first exist. Worth doing before M6 puts a customer lookup in front of a support person who
will believe what it says.

## Make webhook processing converge regardless of delivery order

**What:** Ignore a Stripe event that is older than the state already stored, so an
out-of-order delivery cannot restore a subscription that was cancelled.

**Why:** This is the one critical gap M4 accepted and named. Stripe guarantees
at-least-once delivery and does not guarantee order, and Cashier 16.8 applies whatever
arrives: `handleCustomerSubscriptionUpdated` does `firstOrNew(['stripe_id' => ...])` and
writes the payload's status without comparing it against what is already there. An
`active` update delivered after a `deleted` restores a cancelled subscription. Nothing
tests it, nothing handles it, and the failure is silent — a stale plan until some later
event happens to correct it.

**Pros:** Turns the `--shuffle --duplicate` replay from a test of duplicate side effects
into a real proof of order independence, which is the property the M4 milestone description
originally claimed. It is also the precondition for trusting locally resolved entitlements
during a dunning sequence, which is the whole point of M5.

**Cons:** Event-age guarding means overriding most of Cashier's nine webhook handlers,
which is a large deviation from the package in exactly the place where the upgrade-reading
cost D32 already accepted is highest. Getting the comparison wrong in the other direction
is worse than the bug: drop a legitimate update and the local row is permanently stale.

**Context:** M4 deliberately narrowed its own claim rather than papering over this — the
dunning replay asserts subscription facts and side effects, registers no entitlement
resolver, and therefore promises nothing that depends on convergence (D18). The likely
implementation stores the event's `created` timestamp alongside the subscription and
refuses an update carrying an older one; Stripe's own guidance is to compare event
timestamps rather than arrival order. `failed_webhook_events` (D36) already retains the raw
payloads, so a fix can be validated against real recorded deliveries. Start at
`app/Http/Controllers/Billing/StripeWebhookController.php`.

**Depends on / blocked by:** M5, where entitlement resolution makes a stale subscription
row into a wrong access decision rather than a wrong label on a screen. Do not attempt at
M4 — the milestone was scoped on the explicit understanding that this lands with
resolution.

## Give `subscription_items` its own tenant column

**What:** Add `organization_id` to `subscription_items` and make the model tenant-owned,
rather than relying on it being reached through a scoped parent.

**Why:** D32 made `Subscription` tenant-owned, but Cashier's shipped migration for items is
`$table->foreignId('subscription_id')` and nothing more. Items are safe today only because
every read goes through `$subscription->items`, which Cashier eager-loads via
`protected $with = ['items']`. The first code that queries `SubscriptionItem` directly —
"which organizations are on price X" is the obvious one, and M6's admin console is where it
will be asked — crosses tenants with nothing to stop it.

**Pros:** Makes the boundary a property of the table rather than of every call site, which
is the distinction D20 was written to insist on. Also lets the table be registered with
`TenantQueryGuard`, so the suite starts failing unscoped reads that nobody wrote a test for.

**Cons:** Denormalizes a column that is derivable by joining to `subscriptions`, and the
copy has to be kept true on every write Cashier performs — including the ones inside
`Subscription::syncStripeSubscriptionItems()` that this application does not call directly.
A denormalized tenant key that can drift is worse than an honest join.

**Context:** M4 recorded this as a known limit rather than discovering it later. The
migration is small; the work is in the writes, because Cashier creates and deletes items in
several places (`Subscription.php` lines 767, 779, 949, 1050 in v16.8.0). The likely shape
is a `creating` hook on `App\Models\SubscriptionItem` that fills the column from its parent,
mirroring what `BelongsToOrganization` already does from `TenantContext`.

**Depends on / blocked by:** Nothing structural, but there is no consumer until something
queries items directly. Under D11's filter that makes it M6's problem, arriving with the
admin screen that asks the question.

## Decide how long failed webhook events are kept

**What:** A retention policy, and something that enforces it, for the
`failed_webhook_events` table.

**Why:** D36 retains the raw Stripe payload of any event this application could not place,
so that returning 200 means "recorded" rather than "discarded". Retention at M4 is
permanent and the rows are unredacted Stripe JSON — customer ids, email addresses, card
metadata. A table that only grows and holds personal data is a liability that arrives
quietly, and the day someone asks about data deletion the answer is currently "we kept all
of it forever".

**Pros:** Bounds a table that has no natural ceiling, and gives a straight answer to a
question an adopter of an open-source SaaS kit will reasonably ask. Pruning is also the
moment to decide what to redact, which is cheaper to settle before a year of rows exists.

**Cons:** Pruning fights the reason the table exists. A failed event is kept so it can be
replayed after the bug that broke it is fixed, and bugs are sometimes found months later.
Too short a window and the table is decorative; too long and nothing was really decided.

**Context:** M6 owns the webhook timeline and replay surfaces, which are the first readers
of this table, so the retention question and the replay UI want answering together. Note
the precedent from the entitlements package, which states plainly that its usage counters
and receipts are never pruned "because that is what keeps deduplication correct" — a
deliberate position, published, rather than an oversight. This table deserves the same
treatment: pick a window, say so in the decision record, and enforce it with a scheduled
command. Start at the `failed_webhook_events` migration.

**Depends on / blocked by:** M6, which builds the replay path that decides how old an event
can usefully be.

## Release a project's allowance when the project is deleted

**What:** A way to give an allowance back, so a deleted project stops counting against the
`projects` limit — and the project deletion UI that becomes honest once it exists.

**Why:** M5 models `projects` as a lifetime meter (D39), which equals a stock count only
while nothing is ever deleted. D44 therefore forbids deletion rather than shipping a
customer-visible trap: delete a project on a plan allowing two, and you would still have
none left. That is a worse product than having no delete button.

**Pros:** Closes the gap between "projects you have" and "projects you have ever created",
which is the one assumption the whole lifetime-meter decision rests on. Also unblocks the
ordinary expectation that a resource you created can be removed.

**Cons:** `impruthvi/cashier-entitlements` has no decrement path by design —
`NativeUsage::record()` requires `quantity >= 1`, and the package states plainly that usage
counters and receipts are never pruned, because permanence is what keeps deduplication
correct. A release operation must therefore be additive and auditable rather than a
subtraction, or it breaks the property the package was built around.

**Context:** The likely shape is a receipt-referencing release in the package — an appended
row that offsets a named prior receipt, so the ledger stays append-only and the counter is
a sum rather than a mutable total. M5 already stores `projects.usage_receipt_id`, so the
local half of the association exists. Start at
`vendor/impruthvi/cashier-entitlements/src/Usage/NativeUsage.php` and
`app/Actions/CreateProject.php`. If the release proves unworkable, the fallback is the
design the outside voice argued for during M5's review: enforce with a locked
`count(projects)` and keep the package for the allowance number only — a contained change
behind `ResolveAllowance` and `CreateProject`.

**Depends on / blocked by:** A `cashier-entitlements` release after 0.2.0. Not blocked by
anything in this repository.

## Prune entitlement state for organizations that no longer exist

**What:** A retention policy, and something that enforces it, for the
`cashier_entitlement_*` rows belonging to deleted organizations.

**Why:** The package never prunes counters or receipts — a deliberate published position,
since permanence is what keeps deduplication correct. Deleting an organization therefore
leaves its usage counters, receipts and observations behind forever, keyed by an
`owner_id` that no longer resolves to anything.

**Pros:** Bounds a set of tables that only grows, and gives a straight answer to the data
deletion question an adopter will ask. The rows also carry a billing-adjacent history of an
account that has been closed.

**Cons:** Pruning fights the reason the retention exists, exactly as it does for
`failed_webhook_events`. Deleting a counter for an owner that turns out to be recoverable
reopens the deduplication hole the package closed.

**Context:** These rows are **inert, not dangerous** — `organizations.id` is
auto-incrementing (D26) and PostgreSQL does not reuse sequence values, so no future
organization can inherit a deleted one's usage. This is a storage and data-retention
question, not a correctness one, which is why M5 added no refusal to `DeleteUser` (D34 is
unchanged). Answer it together with the `failed_webhook_events` retention entry above —
both want one window, one decision record, and one scheduled command, not two. Start at the
published `create_cashier_entitlements_usage_tables` migration.

**Depends on / blocked by:** M6, which owns the retention and replay surfaces that decide
how old a record can usefully be.

## Audit every owner from the console in one pass

**What:** `entitlements:reconcile --all`, which currently refuses with `owner_scope_required`
instead of auditing every organization the way the package's own command does.

**Why:** The audit reads each owner's subscriptions through a tenant-scoped relation, and
that scope raises rather than falling back. D41 resolves the tenant around the command from
`--owner`, which works for one organization and cannot work for a loop that picks its own
owners. The loop lives inside the package's `ReconcileCommand::audit()`, and the command is
`final`, so there is nowhere from out here to resolve a tenant per iteration.

**Pros:** Restores an operator tool that answers "is anything drifted anywhere" in one
command. Today the answer needs one invocation per organization, which does not scale past
a few dozen.

**Cons:** Every option moves the tenant decision somewhere it does not belong — reaching
into the package, reimplementing the merged report shape (including `unknown_customers`,
which is computed from a Stripe-wide customer discovery rather than per owner), or teaching
the tenant to resolve itself from whatever model is being read.

**Context:** `entitlements:doctor` still reports across owners and is unaffected, because it
reads the state table rather than any tenant-scoped relation. The per-owner form,
`--owner-type=organization --owner=N`, works for both `--apply` and the dry run and is what
the scheduled sweep and recovery rely on anyway. Start at
`app/Console/Commands/ReconcileEntitlementsCommand.php` and D41.

**Depends on / blocked by:** An upstream seam in `impruthvi/cashier-entitlements` — either a
non-final audit command, or a per-owner callback the host can wrap.
