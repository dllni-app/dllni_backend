# Supervisor backend implementation status

Date: 2026-10-16. Branch: `dev`.

## Implemented and verified

- Dedicated Sanctum supervisor login, role gate, profile and logout.
- Active assignment and explicit `supervised_team` scope on reads and writes. A unique `(cleaning_booking_id, active_slot)` database constraint prevents a second active supervisor and retains released assignment history.
- Operations can make the first active supervisor assignment on an existing supervised booking. Reassignment is blocked; the first assignment is audited.
- Home, paginated/filterable list, schedule, detail, attendance, and timeline. Readiness derives from accepted worker assignments with `arrived_at`; 2 of 3 is not ready.
- Private incident listing/creation and pending replacement requests. Writes are scoped, transactional, audited, and do not change assignment or arrival state.
- Verification, start, extension, handover, and report POST routes give scoped `409 FEATURE_NOT_CONFIGURED` responses.

Verification: `php artisan test tests/Feature/Cleaning/SupervisorApiTest.php --compact` passed **8 tests, 69 assertions** using `sqlite :memory:` and `RefreshDatabase`. `php artisan route:list --path=supervisor` returned **18 routes**: 17 mobile, 1 operations. No dev DB migration, credentials, or `migrate:fresh` were used.

## Pending and not claimed as functional

- There is no safe customer booking creation or conversion path for supervised teams. Test factories create records only in isolated SQLite. The operations route can assign the first supervisor only after such a booking exists; reassignment, scheduling conflict policy, and release remain pending.
- Verification and start require signed-off challenge direction, retry rules, readiness exceptions, and customer authorization. The existing traditional cleaning code cannot be reused as that decision.
- Replacement requests have no dispatch/decision worker swap workflow. They remain pending records for operations to inspect.
- Extension/customer consent, final price, handover/customer confirmation, report sequence, and media retention/access are undefined. Media and report reads/writes are not exposed. Nothing marks paid or completed.
- Selected legacy worker start, complete, security code, and room claim paths now reject supervised bookings; the full legacy worker/customer route set still needs a discriminator audit before production supervised bookings can be created. This API itself never creates room assignments or per-room completion.
- The customer quote and create endpoints return `409 FEATURE_NOT_CONFIGURED` for explicit `serviceType=supervised_team`, before quoting or saving a booking. Ordinary cleaning remains on its existing path.

The existing `CleaningBookingEndpointsTest.php` legacy suite had **38 passes and 1 failure**: `newOrdersCount` was 0 in the worker homepage test, below the expected 1 at line 837. That failure was not resolved by this focused supervisor work. Repository-wide Composer, PHPStan, MySQL acceptance, and end-to-end mobile checks have not been run.

## Phase 3 incremental scheduling integration

- Additive distinct supervised booking identity and integer-minute team scheduling.
- Base minutes divided by 2, 3, 4 workers produce 360, 240, 180 minutes for a 720 minute task.
- Customer quote/creation code is gated by SUPERVISED_TEAM_BOOKING_ENABLED (default false).
- No digital worker-room allocation; normal cleaning pricing reused without supervisor surcharge.
- Operational supervisor reassignment requires a reason and rejects appointment overlaps.
- Never enable live execution until verification, customer consent, overtime and media policies are approved.
- Focused scheduling tests are not proof of end-to-end booking integration.
