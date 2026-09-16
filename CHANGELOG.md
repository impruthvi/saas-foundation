# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Until a `1.0.0` release, minor versions may contain breaking changes; they will always
be listed here under **Changed** or **Removed**.

## [Unreleased]

### Added

- Project skeleton, templated from `shipfastlabs/modern-vue-starter-kit-auth`: Laravel
  13, PHP 8.4, Fortify, Inertia 3 with Vue, Wayfinder, Tailwind, shadcn-vue, Pest 5,
  Larastan, Rector and Pint.
- Governance: MIT licence with upstream attribution retained, contributing guide,
  security policy, code of conduct, and this changelog.
- `tests/Unit/StrictnessTest.php`, pinning the model strictness the tenant boundary
  depends on.

### Changed

- Automatic relationship autoloading is disabled for the test run, so that
  `preventLazyLoading` can actually fire on models drawn from a collection.
- The inherited tree now passes its own formatter, so `composer ci:check` succeeds on a
  clean checkout.

### Removed

- The generated `_ide_helper.php` is no longer tracked. Regenerate it locally with
  `php artisan ide-helper:generate`.

[Unreleased]: https://github.com/impruthvi/saas-foundation/commits/main
