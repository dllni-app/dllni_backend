# Cleaning Booking V2 — Backend Implementation QA (2026-10-16)

**Repository:** `C:\laragon\www\Dllni\Dllni_backend`
**Branch:** `dev`
**Status:** Implemented core V2 improvements; release verification remains incomplete. All pre-existing tracked/untracked changes retained. No commit, push, destructive migration, or production deployment performed.

## Implemented and verified

- New authenticated `POST /api/v1/user/cleaning/orders/materials-preview` accepts only `contractVersion=2` and room keys/types/sizes/regular-or-deep modes. No supplier selection, visit dates, address, or quote required. Uses authoritative active catalog, deduplicated shared needs, stable ordering, and no cost exposure.
- Existing V2 `estimate-price` and create pipelines retained. The quote hash includes optional explicit recurrence and worker count, while preserving the prior preferred-worker normalized-count behavior.
- `customer_partial.materialTypeIds` means the platform-supplied types. Duplicate IDs rejected. Both empty specialist service arrays and explicit room-targeted services persist.
- Optional `recurrence.cadence` validates `once`, `weekly` and `monthly` against explicit visits. Nonexistent monthly day returns field-keyed 422; no silent date adjustment. Custom/unspecified explicit visits remain supported.
- `numberOfWorkers` for open-count bookings is reconciled to simultaneous staffing, without a workers<=rooms restriction. Selected-worker assignment keeps its existing legacy validation.
- Added persisted detail transformer: DB-backed room modes, visit schedules, period supply decisions, material lines, specialist service/room targets, recurrence summary, workforce and approved total. Customer create and GET return the same saved data; saved quote presentation is not used as the source for accepted selections.
- The V2 customer resource hides internal price components, worker accounting and underscore-prefixed private property-detail snapshots. `pricing.coverage=all_visits`.
- Added `docs/fixtures/cleaning-v2/` request/response examples with catalog values marked as placeholders. Expanded `docs/cleaning-v2-time-period-api.md`.

## Test evidence

- `php artisan test tests/Feature/UserModule/CleaningTimePeriodV2Test.php --stop-on-failure` — V2 suite verified, with new supplier mode, auth, ownership, quote, recurrence and create/GET tests. **35 passed / 225 assertions**. Latest output: `storage/logs/cleaning-v2-qa-final.log`.
- Combined existing V2/room-mode/compatibility tests — **52 passed / 314 assertions** prior to last additional cases.
- Selected legacy/recurrence/worker/pricing/event regressions — **124 passed / 627 assertions**, output `storage/logs/cleaning-v2-qa-legacy.log`.
- `git diff --check` — no whitespace errors (existing Windows CRLF warnings only).
- All newly added/touched PHP files passed `php -l` checks; Pint was applied to the three new/modified V2 services.

## Important outstanding release gates

1. **Strict static analysis not green.** Targeted `php vendor/bin/phpstan analyse ... --no-progress --error-format=table` reported **108 diagnostics** across four new files, mostly dynamic Eloquent model relation/property inference at maximum configured analysis level plus missing array types. See `storage/logs/cleaning-v2-static.log`. No suppressions added. This needs dedicated typing work before declaring the PHPStan gate satisfied.
2. **True cross-process concurrency** for quote consumption/idempotent booking and inventory reservation is not established by SQLite sequential tests. Run controlled MySQL/Redis concurrent calls with isolated test fixtures and verify one booking and one reservation.
3. **Flutter cross-repository contract approval and emulator workflow** not done in this backend-only pass. In particular, the multi-worker optional lead/supervisor contract must be agreed with Flutter and worker-assignment owners; `preferred_worker` retains its legacy semantics and has NOT been repurposed to claim accepted supervision.
4. **Production deployment/migration and broader full-suite release gate** not run. Existing V2 migrations remain in worktree; no database reset or live schema mutation was performed.
5. **Code style:** strict Pint `--test` reports pre-existing and touched formatting deviations in the large StoreController, customer resource, contract validator and V2 test file. Avoid wide unrelated reformatting without reviewing the existing uncommitted work.
6. User-facing V2 detail is additive and draws from normalized rows, but operational status/repricing and worker assignment should be verified with the live worker/client lifecycle and existing mobile clients.

## API handshake

- Materials preview: `POST /api/v1/user/cleaning/orders/materials-preview` with `{contractVersion:2, rooms:[{roomKey,roomType,size,cleaningMode}]}`.
- Quote: existing `POST /api/v1/user/cleaning/orders/estimate-price`; `quoteId`, `quoteSignature`, `quoteExpiresAt`, canonical `visits`, `requiredSimultaneousWorkers`, `pricing.totalPrice`.
- Create: existing `POST /api/v1/user/cleaning/orders`; accepts approved quote and `idempotencyKey`, returns `order`, `rooms`, `visits`, `recurrence`, `workforce`, `pricing`.
- Detail: existing `GET /api/v1/user/cleaning/orders/{order}`; V2 fields inside `data` resource.
