# Contributing

Thank you for considering a contribution.

This project is pre-alpha and has one maintainer. That shapes what is useful to send.

## Before you write code

**Open an issue first for anything beyond a bug fix or a typo.** The scope is
deliberately narrow and enforced by a written filter: if a capability does not
strengthen the ten-minute journey in the README, or validate a core boundary, the
extension point gets documented and the implementation gets deferred.

That rule has already turned down good ideas. Opening an issue first means finding out
before you spend a weekend, not after.

Deliberately out of scope for V1: additional frontend variants, additional payment
providers, database-per-tenant or multi-region tenancy, a marketplace or plugin store,
advanced tax and revenue recognition, enterprise SSO and SCIM, a full analytics suite,
and an AI platform.

## Getting set up

Requires PHP 8.4+, Node 22+, and Composer.

```bash
git clone https://github.com/impruthvi/saas-foundation.git
cd saas-foundation
composer setup
```

`composer setup` installs both dependency trees, creates `.env`, generates a key, runs
migrations and builds the frontend.

PostgreSQL is the documented path. SQLite works for local development and is what the
test suite uses.

## Before you open a pull request

```bash
composer ci:check
```

That is the same gate CI runs: oxlint, oxfmt, `vue-tsc`, Pest, Pest type coverage, Pint,
Rector and PHPStan. **It should pass on a clean checkout before you change anything.**
If it does not, that is a bug worth reporting on its own.

Most style questions are answered by running `composer lint` and `npm run format`
instead of discussing them.

## What a good pull request looks like

- **One idea.** Two unrelated changes are two pull requests.
- **A test that fails without your change.** For anything touching tenant scoping,
  authorization, entitlement resolution or billing state, this is not negotiable — those
  are the boundaries the project exists to get right.
- **Commit messages that explain why.** The diff already says what changed. The history
  is where the reasoning lives, and it is read far more often than it is written.
- **No drive-by reformatting** of code you are not otherwise touching.

## If you work with an AI agent

`AGENTS.md` and `CLAUDE.md` are tracked and carry this project's conventions — the
Action pattern, the fixed vocabulary, and the framework-specific rules Laravel Boost
derives from the installed packages. Your agent should read one of them before writing
code here.

`.mcp.json` points at the Boost MCP server, which gives an agent version-accurate
documentation for the exact versions this project installs. That matters more than
usual here: Laravel 13, Fortify, Inertia 3 and Pest 5 are recent enough that a model
working from memory will confidently produce APIs that do not exist.

Agent skills are not tracked, because Boost owns them and reproduces them identically.
Install them locally:

```bash
php artisan boost:install --skills
php artisan boost:update      # refresh guidelines after a dependency change
```

`boost.json` is not tracked, because it records which agent you personally use. If
`boost:update` reports that Boost is not set up, that file is missing its `agents` key —
re-run `boost:install` and answer the agent prompt.

The orientation section at the top of `AGENTS.md` and `CLAUDE.md` sits outside Boost's
`<laravel-boost-guidelines>` tags and survives `boost:update`; verified, not assumed.

None of this is required to contribute. The gate is `composer ci:check`, not the
tooling you used to get there.

## Vocabulary

The domain terms — organization, membership, plan, price, subscription, feature,
entitlement, allowance, limit, override, usage meter, increment, resolution,
reconciliation — have fixed meanings, and the alternatives they displace are listed
alongside them. Please use them as defined rather than introducing synonyms; a pull
request that renames `organization` to `team` will be asked to change it back.

## Security

Do not report security problems in a public issue. See [SECURITY.md](SECURITY.md).

## Conduct

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).

## What to expect

Issues are triaged weekly. There is no response-time SLA and no LTS; releases happen
when they are ready. If a pull request goes quiet, a polite nudge is welcome and not
considered rude.
