# Backend Composer security refresh — integration gate

Date: 2026-10-22. Status: **HOLD pending runtime upgrade and regression tests**.

## Evidence

- Baseline `dev` (`f5b45aeb`) reported 65 Composer dependency advisories with `composer audit --locked` (17 high among the affected package advisories). The exact advisory set is time-dependent.
- The isolated refresh updates the minimum application PHP version to `^8.4.27`, Filament from an exact `5.3.5` pin to `^5.10.3`, and the Composer lockfile. This resolves the currently reported security advisories (`composer audit --locked` reports **no security vulnerability advisories**).
- The resolution involved 132 dependency upgrades, six installs, and six removals, including Laravel 12.58.0 → 12.70.1 and Filament 5.3.5 → 5.10.3; it is a significant dependency change with potential compatibility impact.
- `composer validate --strict --no-check-publish` passes. `composer audit` returns a nonzero status because an abandoned development package remains in the resolved graph (`symplify/rule-doc-generator-contracts`), even though it reports no security vulnerabilities.
- The globally selected Laragon CLI runtime is PHP 8.4.14 and remains unchanged. The initial `composer check-platform-reqs --lock --no-dev` correctly failed on it. Composer's platform requirement was ignored **only during isolated lock resolution**.
- An isolated official PHP 8.4.27 runtime with separate `php.ini` and supported extensions successfully ran `composer install` with no platform override, Composer's package discovery and Filament asset publishing, `composer check-platform-reqs --lock`, and `npm run build`. Its test-only runtime and generated local `.env` remain untracked. This verifies an isolated Windows install; application, queue, web and production PHP runtimes still require upgrades and deployment validation.
- The upgraded PHP dependencies passed the 11-file focused SQLite cleaning/supervisor/Filament regression suite: **143 tests, 1,244 assertions**, with no failures, using an isolated in-memory database and test-only APP_KEY.
- A separate six-file Filament regression suite passed **38 tests, 294 assertions**, covering company panel access, financial settings, supervisor booking/visit actions, V2 admin resources and room catalog.
- The original npm lockfile reported 10 vulnerabilities (two critical, seven high, one moderate). An isolated `npm audit fix --package-lock-only --ignore-scripts` refreshed `package-lock.json`; subsequent `npm audit --audit-level=moderate` reports **zero vulnerabilities**. The application JavaScript build and browser flows still require verification against installed packages.
- `npm ci` succeeds on the isolated tree, and `npm run build` now succeeds after the patched PHP Composer installation populated the needed Filament `vendor/` CSS assets.
- The tracked `.env.example` previously contained nonempty SMS credentials and enabled a test endpoint. The security branch clears both credential example values and defaults `MTN_SMS_TEST_ENDPOINT_ENABLED=false`. If those exposed values were ever valid, **rotate/revoke them**; editing current Git history cannot undo earlier exposure.

## Release gate

1. Install and select a patched compatible PHP runtime **8.4.27 or later within the supported PHP 8.4 series** on build/test and deployment hosts. Confirm `php -v` in those environments, along with all required extensions.
2. Run `composer install --no-interaction` **without** `--ignore-platform-req`, then `composer check-platform-reqs`, `composer audit --locked`; run `npm ci` and `npm audit`, rebuild Filament/Vite assets, and perform application smoke checks.
3. Run focused auth/supervisor/booking/settlement and Filament suites, then full Pest, PHPStan, and native MySQL migration/upgrade tests on disposable environments. Validate authenticated browser and mobile flows before release.
   The new `.github/workflows/backend-security-refresh.yml` gate pins PHP 8.4.27 and Node 22, checks Composer platform/security, installs and audits npm, builds Vite with installed Filament vendor assets, and runs isolated auth/supervisor security tests on the candidate.
4. Get independent review of the dependency/API upgrades and any remaining abandoned-package alternatives. Merge only after those gates pass.

This candidate branch is intentionally separate from live `dev`; no production dependencies, databases or existing PHP installations were changed.
