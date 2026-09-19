# Laravel SaaS Foundation

An open-source Laravel SaaS foundation: the layer built above authentication —
organizations, tenant-scoped authorization, subscriptions, entitlements, usage and
operations. This glossary fixes the vocabulary. Implementation lives in
`docs/decisions/` and `docs/rfcs/`, never here.

## Language

### Tenancy

**Organization**:
The tenant. Owns data, holds the subscription, and is the subject of every
entitlement question. A solo user gets one containing only themselves.
_Avoid_: team, workspace (acceptable as UI copy only), account, tenant

**Membership**:
The link between a user and an organization, carrying rank and status.
_Avoid_: team member, seat (a seat is a billed quantity, not a person)

**Rank**:
What a membership says a person is: Admin or Member, and never Owner, because
ownership is `organizations.owner_id` (D23). The one writable fact about standing
inside an organization. `MembershipRole` is its enum, kept for the column it casts.
_Avoid_: role (that is the RBAC bundle below), level, tier

**Role**:
A named bundle of permissions granted inside one organization, held in the RBAC
tables. Derived from rank and never written on its own (D31). `OrganizationRole` is
its enum.
_Avoid_: group, rank (that is the membership fact above)

**User**:
A human identity. Never the subject of billing or entitlements.
_Avoid_: account, customer

### Commerce

**Plan**:
A named commercial offering that grants a set of allowances. Application-owned and
provider-independent.
_Avoid_: tier, package, product

**Price**:
A provider-specific way to pay for a plan — interval, currency, amount, provider price
id. One plan may have several.
_Avoid_: plan id, SKU

**Subscription**:
An organization's ongoing commercial relationship to a plan, as last reported by the
provider.
_Avoid_: membership, plan

**Billing fact**:
Something the provider asserts: which plan is active, the period, trial state,
cancellation state. Inputs to entitlement resolution, never the answer.
_Avoid_: subscription status (too narrow), Stripe state

### Access

**Feature**:
A stable application capability key, such as `projects` or `ai.generate`. Owned by the
application, never by the payment provider.
_Avoid_: permission (that is RBAC), flag, capability

**Entitlement**:
What an organization may do with a feature right now — the resolved answer.
_Avoid_: subscription feature, plan feature (that is the mapping, not the answer)

**Allowance**:
The value an entitlement resolves to: a boolean for access, or a number for a limit.
_Avoid_: quota (ambiguous between allowance and remaining), grant

**Limit**:
An allowance that is numeric.
_Avoid_: cap, max, quota

**Override**:
A time-bound, reasoned, audited adjustment to one organization's allowance, outranking
its plan.
_Avoid_: exception, grandfather, custom plan

### Consumption

**Usage meter**:
A named counter of how much of a feature an organization has consumed in a period.
_Avoid_: usage record, counter (alone), metric

**Usage period**:
The window a meter counts within — billing-aligned or calendar, declared per feature.
Rollover starts a new count; it never resets an existing one.
_Avoid_: cycle, month, billing period (that is a billing fact)

**Increment**:
One idempotent addition to a meter, carrying a caller-supplied idempotency key.
_Avoid_: usage event (that is the stored record), tick, report

**Remaining**:
Limit minus usage for the current period.
_Avoid_: available, left, balance

### Operations

**Resolution**:
Computing an entitlement from billing facts, plan mapping and overrides. Never performs
a provider API call.
_Avoid_: check, evaluation, lookup

**Reconciliation**:
Comparing current provider billing facts with the application's local projection and
bringing applied entitlements into agreement with declared policy. Provider reads happen
in background work; resolution remains local. Local recomputation alone cannot discover
missing provider events.
_Avoid_: replay (that delivers recorded events), local recomputation (only one step)
