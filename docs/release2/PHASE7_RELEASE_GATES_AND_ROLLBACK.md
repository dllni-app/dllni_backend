# Release 2 — Phase 7 Integration Gate & Rollback Runbook

Date: 2026-10-11
Current status: **NO GO (NOT APPROVED FOR PRODUCTION)**.

## Controlled repositories and branches

- Backend: `dllni_backend/dev`
- Customer Flutter: `dllni-user-app/dev`
- Worker Flutter: `dllni_cleaning_owner_app/main` (workflow changes proposed in a PR)
- Other connected merchant apps are out of the cleaning Release 2 activation path and must remain untouched.
- The previous 116-control requirement baseline remains **incomplete** without the original signed SRS PDF: 40 individually named roadmap controls have partial references, 76 are not source-enumerated.

## Phase 7 gate matrix

| Gate | Evidence / run command | Status |
| --- | --- | --- |
| SQLite business/permission regression | `php artisan test` against `phpunit.xml`; Phase 6 passed 189 selected Backend/Filament tests + 1593 assertions | PASS for Phase 6 subset only |
| Flutter unit/widget / contract | Phase 6: 60 customer and 29 worker tests passed | PASS for Phase 6 subset only |
| MySQL 8.4 migrations | Isolated schema `release2_phase7_gate_20261011`; full clean install after naming FK constraints | PASS, local disposable schema |
| MySQL admin audit rollback/reapply | `php artisan migrate:rollback --step=1` then `php artisan migrate` on disposable schema only | PASS |
| Independent InnoDB equipment lock | `php scripts/release2/probe_equipment_lock.php` (refuses every other schema), two real PDO connections, one-second lock timeout, re-acquire after release | PASS, engine-level lock only |
| MySQL Pest business-flow suite | `php vendor/bin/pest --configuration=phpunit.release2.mysql.xml` with three feature test files | **FAIL/BLOCKED**: `RefreshDatabase` recreates schema during tests, causing DDL deadlocks/table-missing errors. Must fix test harness; never treat as a business validation pass |
| Notification queue and realtime security | **30 passed, 274 assertions** in nine SQLite suites: order, travel, worker-acceptance, availability, timing, extension notifications and private-channel authorization | PARTIAL: external queue, FCM and realtime delivery not E2E verified |
| Migrations on current development DB | Read-only `migrate:status` showed `2026_10_10_230000_add_cleaning_admin_operational_audit` PENDING | ACTION REQUIRED before exercising Phase 3 features; no migration run on development DB |
| Original Release 2 SRS traceability | `PHASE6_TRACEABILITY_EVIDENCE.csv` generated from known roadmap IDs only | BLOCKED |
| Customer approval for post-assignment service amendments, recurring pause date range, initial specialist dispatch fairness | Phase 6 report | OPEN P0/P1 |
| Deploy/rollback dry run with realistic queues, notifications and finance | No real app/emulator or live environment used | NOT VERIFIED |
| Dependency security | GitHub reported 64 Dependabot findings on backend default branch during push (18 high, 36 moderate, 10 low) | ACTION REQUIRED |

The previous notification timing and worker-confirmation fixtures lacked dispatch-eligible home coordinates/address. They were repaired in *tests only*, without weakening radius or work-hour enforcement. The corrected nine-suite notification regression now passes 30 tests / 274 assertions.

A workflow file `.github/workflows/release2-integration-gates.yml` is triggered by Backend `dev` changes and review PRs. It runs SQLite API/Filament contracts, isolated MySQL migrations, rollback/reapply and cross-connection lock probe. The MySQL Pest stage intentionally remains a hard **red** gate while its cross-database test fixture infrastructure is unresolved. The two Flutter app workflows must be active on their integration branches (customer `dev`, worker `main`). These workflows use CI runners rather than real devices.

## Required pre-production checklist (manual, not executed here)

1. Ensure a signed-off original SRS with exactly 116 source-traced controls, all P0/P1 bugs closed and all CI jobs green. Record Git SHAs of Backend, customer and worker. If a red job is waived, Release 2 is **not** fully accepted.
2. Take timestamped DB backup/snapshot and verify a restore on a separate machine/schema. Back up media uploads, external notification credentials references and encrypted environment configuration. Do not store secrets or real customer data in CI artifacts.
3. Verify MySQL engine version, required extensions, indexes and disk capacity; run `php artisan migrate:status` against intended deployment database. Never use `migrate:fresh`, `db:wipe` or `migrate:rollback` on a live DB during deployment.
4. Plan compatible contract sequencing: deploy backward-compatible Backend first; let `php artisan migrate --force` run through the normal supervised deployment process **after backup**, then restart queue workers `php artisan queue:restart` and scheduler/realtime infrastructure using deployment manager.
5. Only enable new Flutter user and worker interfaces after backend API and migrations are healthy. Worker application is integrated through `main`; do not switch it to `dev` implicitly.
6. Verify migration audit table, permissions, server timer, equipment reservation and administrative return handling, queues, notifications and websocket authorization with privacy-safe headless probes in an isolated staging environment.
7. Reconcile sample bookings with canonical financial ledger, worker payout and line-item snapshots, including multi-day cancellations and reassignment.
8. Check error rate, deadlocks, queue backlog and notification failure alerts. Preserve feature rollback switches and pre-deploy artifacts until sign-off.

## Rollback strategy

- Prefer **application and feature-flag rollback**, preserving new DB columns and audit logs, because newly written bookings, equipment handovers and financial records can depend on the additive schema.
- Keep the previous compatible API release available. Disable incomplete features and new UI entry points; pause new reservations if needed while workers settle running bookings.
- Revert Flutter release rollout through the configured distribution mechanism only after confirming compatible backend response fields.
- Never automatically roll back a migration on production when any Phase 3 audit events or equipment return confirmations may have been recorded: dropping these columns/tables destroys history.
- If database recovery is unavoidable, use the verified backup to restore to a controlled environment and perform a reviewed, reversible forward data repair. Obtain approval before any destructive live action.
- Document operator, UTC timestamp, deployment SHAs, queue state, decision rationale and financial reconciliation outcome.

## Known test limitations

- The independent-connection lock probe proves row-lock serialization, **not** complete correctness of concurrent booking acceptance or transaction retries. MySQL multi-process API races are a separate blocker.
- SQLite/Pest tests use `Notification::fake`, `Queue::fake`, array mail or synchronous processing in many paths. These tests do not prove push delivery/realtime fan-out through external providers.
- Physical-device/emulator testing was intentionally not performed.
- A green targeted CI job is necessary but not sufficient for the user-requested full Release 2 acceptance report.

**Decision: HOLD RELEASE 2.** Finish the MySQL test harness, run the full cross-platform integration suite in staging, resolve open features, and obtain original SRS acceptance evidence before revisiting the release gate.
