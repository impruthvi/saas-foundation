# 0002 — Inherited tooling audit

- **Date:** 2026-09-16
- **Status:** Accepted
- **Decided by:** Pruthvisinh Rajput
- **Discharges:** D19's accepted cost — _"inherited choices nobody on this project made
  get one deliberate audit pass and are then either kept with a reason or removed with
  one."_
- **Milestone:** M0 of `docs/plans/0002-foundation-vertical-slice-build-plan.md`
- **Applies to:** `impruthvi/saas-foundation`, templated from
  `shipfastlabs/modern-vue-starter-kit-auth`

Six inherited choices, six decisions. Two required code changes, one exposed a defect
that was not on the list, three are kept as they arrived.

## 1 — Automatic relationship autoloading disarms the lazy-loading guard

**Changed.** `nunomaduro/essentials` enables both `ShouldBeStrict` and
`AutomaticallyEagerLoadRelationships`. They interact, and the interaction is invisible
from the config file. In `HasAttributes::getRelationValue()` autoloading is attempted
first and returns early when it succeeds; the `preventsLazyLoading` check sits _after_
it. Every Eloquent collection has `withRelationshipAutoloading()` applied in
`HasCollection::newCollection()`, so any relation accessed on a model drawn from a
collection is quietly resolved and the violation never throws — for precisely the case
the guard exists to catch.

Both stay enabled in development and production, where autoloading is a real
convenience. Autoloading is disabled for the test run only, in `tests/Pest.php`.

**Why:** a guard that cannot fail is not a guard, and the foundation's tenant scoping is
enforced at the model boundary (D3, and M1's architecture test). A loud failure there is
worth more than a saved query in a test. This is not an invented workaround — the
framework does the same thing itself in `Factory::createChildren()`.

`tests/Unit/StrictnessTest.php` pins all four facts so relaxing any of them later is a
deliberate act with a failing test attached.

## 2 — The generated IDE helper is no longer tracked

**Removed.** `_ide_helper.php` was 29,796 generated lines in the tree. Nothing references
it — not `phpstan.neon`, not `rector.php`, not composer scripts, not CI — because nothing
is meant to. It goes stale the moment a dependency moves, and it lands in every diff a
contributor reads. `barryvdh/laravel-ide-helper` stays in `require-dev`; the generator is
useful, committing its output is not.

## 3 — oxlint and oxfmt are kept over ESLint and Prettier

**Kept.** The younger ecosystem is a real cost and ESLint/Prettier is what a contributor
expects by default. Kept anyway, because inheriting the upstream kit's conventions _is_
D19's entire justification, and swapping them re-opens the question D19 closed. Both pass.

## 4 — `laravel/chisel` is kept, and it is load-bearing

**Kept, and promoted from unexamined to required.** It is a toolkit for removing code,
files and dependencies. That is the mechanism D4 needs for _"admin must remain removable
without dead navigation or broken tests"_ and D11 needs for optional modules. It arrived
free and does a job the plan already committed to. Had the audit not looked, this would
have been rebuilt by hand at M6.

## 5 — `.ai/guidelines/` is kept and will be extended

**Kept.** One file today. D16 already makes agent discoverability a V1 deliverable rather
than an afterthought; this is the same surface and the cost is near zero.

## 6 — Passkeys are kept, and D11's parking of them does not apply

**Kept.** D11 parked passkeys as something not to _build_ before the package shipped.
These were not built here — they arrive as `laravel/passkeys` wired into
`config/fortify.php`, first-party and already tested. Removing working first-party
authentication to honour the letter of a decision aimed at scope creep would be a
misreading of it.

## Finding not on the list — CI failed on a clean checkout

`composer ci:check` runs `npm run format:check`, and it failed on a fresh clone: nine
inherited files did not satisfy the oxfmt configuration shipped beside them, including
`.oxfmtrc.json` and `.oxlintrc.json` themselves, the CI workflow and the dependabot
config. Not version drift — `package.json` declares `oxfmt ^0.64.0` and 0.64.0 is
installed. They were committed unformatted.

Fixed by running the formatter. Every change was verified cosmetic rather than assumed:
`.oxlintrc.json`, `composer.json` and `package.json` were parsed before and after and
compared as data structures, and all three are identical.

**Why it matters beyond tidiness:** a gate that fails on a clean checkout teaches every
contributor to ignore it, and after that it cannot report a real failure.

## Consequences

- `composer ci:check` passes on a clean checkout, on both supported databases.
- The suite is proven on PostgreSQL and SQLite on every push (D3), verified locally
  before the claim was written: 46 tests, 184 assertions, both engines.
- M1's architecture test for unscoped tenant queries can now rely on strictness actually
  firing.
- M6's removable-admin requirement has a named mechanism instead of an assumption.
