# Security Policy

## Supported versions

Fixes land on `main` and in the next release. Only the latest release is supported, currently
`v0.2.0`; older releases do not receive fixes.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Use GitHub's private vulnerability reporting on this repository:
**Security → Report a vulnerability**. It is private between you and the maintainer, and
it keeps the report attached to the code it concerns.

Please include what you need to make the problem reproducible: affected version or
commit, configuration that matters, and the steps. A proof of concept helps and is never
required.

## What to expect

- **Acknowledgement within 7 days.**
- An assessment of severity and affected versions once the report is understood.
- A fix released when it is ready, with credit in the release notes unless you would
  rather not be named.

There is **no response-time SLA beyond the acknowledgement**, and no LTS. This project
has one maintainer and the promise is deliberately one that can be kept.

## Scope

In scope: this repository's application code, its default configuration, and the
security-relevant behaviour of the modules it ships — tenant isolation, authorization,
entitlement resolution, and billing state.

**Tenant isolation is the one to look hardest at.** An organization reading, writing or
being billed for another organization's data is the most serious class of bug this
project can have.

Out of scope: vulnerabilities in upstream dependencies — report those to their
maintainers — and findings that require an already-compromised host or database.
