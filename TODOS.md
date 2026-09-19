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

**Cons:** `npm install playwright@latest && npx playwright install` downloads browser
binaries, which is a real cost on CI and on a fresh clone, and it is the kind of thing
that should be a deliberate choice rather than a side effect of someone running a test.

**Context:** Start by running those two commands and then
`vendor/bin/pest tests/Browser`. Adding a `Browser` testsuite to `phpunit.xml` is one
block, but do it _after_ the binaries work — wiring a suite that immediately fails turns
a known gap into a red build. Consider whether browser tests belong in `composer test` at
all or in a separate `composer test:browser` that CI runs as its own job, since they are
slower than the rest of the suite combined.

**Depends on / blocked by:** Nothing. This is unblocked work that was deferred because
installing browser binaries is the user's call, not the agent's.
