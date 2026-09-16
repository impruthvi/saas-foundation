# Documentation

Start here if you are new to this repository — human or agent.

## Read in this order

1. **[`../CONTEXT.md`](../CONTEXT.md)** — the domain vocabulary. Organization, membership,
   plan, price, subscription, feature, entitlement, allowance, limit, override, usage
   meter, increment, resolution, reconciliation. Each term lists the words it displaces.
   These meanings are fixed and are not renegotiated in code review.
2. **[`decisions/0001-architecture-decisions.md`](decisions/0001-architecture-decisions.md)**
   — the decisions that bind the codebase, numbered. Commit messages cite them by number.
3. **[`decisions/0002-inherited-tooling-audit.md`](decisions/0002-inherited-tooling-audit.md)**
   — what was inherited from the upstream template and what was decided about each piece.
4. **[`plans/0001-vertical-slice.md`](plans/0001-vertical-slice.md)** — the build order,
   M0 through M7, and what counts as done for each.

## The one rule that explains the rest

A milestone is done when **its segment of the ten-minute journey runs**, not when its
code exists. The journey is in D8 and at the top of the build plan.

## Where the rest lives

Product strategy, competitive analysis and launch history are kept in a separate private
planning repository and are deliberately not reproduced here. `0001` notes which decision
numbers are omitted and why. Nothing in this repository depends on reading them.

## Related packages

- [`impruthvi/cashier-entitlements`](https://github.com/impruthvi/cashier-entitlements) —
  entitlement resolution, usage meters, audited overrides, reconciliation. This project
  is its first consumer.
- [`impruthvi/cashier-dunning`](https://github.com/impruthvi/cashier-dunning) — replays
  recorded Stripe billing lifecycles offline through the real webhook route. Enters as a
  `require-dev` dependency at M4.
