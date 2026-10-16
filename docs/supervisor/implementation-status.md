# Supervisor backend implementation status

Date: 2026-10-16. Branch: `dev`.

## Implemented and verified

- Dedicated Sanctum supervisor login, role gate, profile and logout.
- Active assignment and explicit `supervised_team` scope on reads and writes. A unique `(cleaning_booking_id, active_slot)` database constraint prevents a second active supervisor and retains released assignment history.
- Home, paginated/filterable list, schedule, detail, attendance, and timeline. Readiness derives from accepted worker assignments with `arrived_at`; 2 of 3 is not ready.
- Private incident listing/creation and pending replacement requests. Writes are scoped, transactional, audited, and do not change assignment or arrival state.
- Verification, start, extension, handover, and report POST routes give scoped `409 FEATURE_NOT_CONFIGURED` responses.

Verification: `php artisan test tests/Feature/Cleaning/SupervisorApiTest.php --compact` passed **7 tests, 60 assertions** using `sqlite :memory:` and `RefreshDatabase`. `php artisan route:list --path=supervisor` returned **17 routes**. No dev DB migration, credentials, or `migrate:fresh` were used.

## Pending and not claimed as functional

- There is no safe customer booking creation or conversion path for supervised teams, and no approved operations endpoint to assign the role or booking. Test factories create records only in isolated SQLite. The active assignment constraint is ready for a future audited operation.
- Verification and start require signed-off challenge direction, retry rules, readiness exceptions, and customer authorization. The existing traditional cleaning code cannot be reused as that decision.
- Replacement requests have no dispatch/decision worker swap workflow. They remain pending records for operations to inspect.
- Extension/customer consent, final price, handover/customer confirmation, report sequence, and media retention/access are undefined. Media and report reads/writes are not exposed. Nothing marks paid or completed.
- Selected legacy worker start, complete, security code, and room claim paths now reject supervised bookings; the full legacy worker/customer route set still needs a discriminator audit before production supervised bookings can be created. This API itself never creates room assignments or per-room completion.
- The customer quote and create endpoints return `409 FEATURE_NOT_CONFIGURED` for explicit `serviceType=supervised_team`, before quoting or saving a booking. Ordinary cleaning remains on its existing path.
- Other concurrent untracked supervisor route/customer/operations files and adjacent traditional route edits were present in the working tree. They are not included from `Modules/Cleaning/routes/api.php` and were not validated by this focused test. Review them before any broader commit or release.

The existing `CleaningBookingEndpointsTest.php` legacy suite had **38 passes and 1 failure**: `newOrdersCount` was 0 in the worker homepage test, below the expected 1 at line 837. That failure was not resolved by this focused supervisor work. Repository-wide Composer, PHPStan, MySQL acceptance, and end-to-end mobile checks have not been run.
