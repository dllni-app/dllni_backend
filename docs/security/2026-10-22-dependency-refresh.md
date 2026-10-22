# Backend Composer security refresh — integration gate

Date: 2026-10-22. Status: **HOLD pending runtime upgrade and regression tests**.

## Evidence

- Baseline `dev` (`f5b45aeb`) reported 65 Composer dependency advisories with `composer audit --locked` (17 high among the affected package advisories). The exact advisory set is time-dependent.
- The isolated refresh updates the minimum application PHP version to `^8.4.27`, Filament from an exact `5.3.5` pin to `^5.10.3`, and the Composer lockfile. This resolves the currently reported security advisories (`composer audit --locked` reports **no security vulnerability advisories**).
- The resolution involved 132 dependency upgrades, six installs, and six removals, including Laravel 12.58.0 → 12.70.1 and Filament 5.3.5 → 5.10.3; it is a significant dependency change with potential compatibility impact.
- `composer validate --strict --no-check-publish` passes. `composer audit` returns a nonzero status because an abandoned development package remains in the resolved graph (`symplify/rule-doc-generator-contracts`), even though it reports no security vulnerabilities.
- The available Laragon CLI runtime is PHP 8.4.14. `composer check-platform-reqs --lock --no-dev` correctly fails its PHP requirement. Composer's platform requirement was ignored **only during isolated lock resolution**; this is not permission to install or deploy the refreshed dependencies on PHP 8.4.14.

## Release gate

1. Install and select a patched compatible PHP runtime **8.4.27 or later within the supported PHP 8.4 series** on build/test and deployment hosts. Confirm `php -v` in those environments, along with all required extensions.
2. Run `composer install --no-interaction` **without** `--ignore-platform-req`, then `composer check-platform-reqs`, `composer audit --locked`, Filament asset rebuilds, and application smoke checks.
3. Run focused auth/supervisor/booking/settlement and Filament suites, then full Pest, PHPStan, and native MySQL migration/upgrade tests on disposable environments. Validate authenticated browser and mobile flows before release.
4. Get independent review of the dependency/API upgrades and any remaining abandoned-package alternatives. Merge only after those gates pass.

This candidate branch is intentionally separate from live `dev`; no production dependencies, databases or existing PHP installations were changed.
