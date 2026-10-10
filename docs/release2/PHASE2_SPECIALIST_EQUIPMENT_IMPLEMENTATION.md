# Release 2 / Phase 2 — Specialist permissions and equipment reservations

Date: 2026-10-10
Repository: `dllni-app/dllni_backend`
Implementation branch: `dev`
Original SRS: NOT locally available for literal requirement-by-requirement acceptance mapping.

## Scope and status

This is **incremental implementation**, not full Release 2 or Phase 2 acceptance approval. The user-provided roadmap names SS-13, SS-14, SS-16, SS-19, SS-20 and AC-14/AC-15, but does not include their exact SRS prose.

| Behavior | Code source | Verification |
| --- | --- | --- |
| Explicit active, approved specialist qualification | `CleaningSpecialistAuthorizationService` | `CleaningSpecialistReservationGateTest` |
| Separate active, approved equipment authorization | Same service, separate authorization relation | `CleaningSpecialistReservationGateTest` |
| Session discovery and acceptance reject unqualified workers | `CleaningBookingSessionWorkerEligibilityService` and `CleaningBookingSessionAcceptanceService` | `CleaningBookingSessionAcceptanceTest` |
| Schedule-replacement worker list applies specialist permissions | `CleaningScheduleChangeApprovalService` | Code-review evidence only |
| Reservation created transactionally on accepted assignment (not start) | `CleaningSpecialistEquipmentReservationService`, session and booking team services | `CleaningBookingSessionAcceptanceTest`, `CleaningOperationalExtrasLifecycleTest` |
| Equipment overlap including before/after buffers blocked | `CleaningSpecialistEquipmentReservationService` | `CleaningSpecialistReservationGateTest` |
| Asset-row `FOR UPDATE` protects the empty-reservation race in transactional writes | Same service | Code-review evidence, **no multi-connection concurrency stress test yet** |
| Worker reassignment reuses safely released reservation; multi-session lines have separate windows | Same service | `CleaningSpecialistReservationGateTest`, `CleaningBookingSessionAcceptanceTest` |
| Release on regular worker rejection, selected session release, recurring pause/skip, multi-day worker removal | Team, worker change, cancellation, recurring services | Targeted regression tests, not exhaustive |
| Recalculate reservation time for retained worker on event-session reschedule | `EventAssistanceSessionRescheduleService` | Code-review evidence only |
| Start rejects absent reservation or revoked specialist/equipment authorization | `CleaningOperationalExtrasService` | `CleaningSpecialistReservationGateTest` |

## Important unclosed acceptance risks

1. **Exact original SRS statements** must be mapped before asserting SS/AC completion.
2. Filament direct model edits and every other schedule-mutating path (including recurring schedule revision approval and admin reassignment) still need explicit reservation reconciliation hooks and tests.
3. Equipment reservation concurrency should be tested with distinct MySQL connections against the target database engine; the current regression tests are sequential.
4. Preexisting in-progress special-service bookings and previously issued reservations need a controlled migration/compatibility policy before deployment. Do not silently invent authorizations, approvals, or equipment history.
5. Multi-session service lines currently store one `assigned_worker_id`, which is a modeling limitation if each visit intentionally has a *different* specialist worker.
6. Equipment worker handover, fault reporting, and return approval workflow belong to roadmap Phase 3 and are not delivered by this Phase 2 change.
7. User/worker Flutter and Filament end-to-end contracts, visibility, administrator authorization management, and full regression have not yet been validated.
8. No physical-device or emulator testing was performed, as instructed.

## Test runs (backend only)

- `CleaningBookingSessionAcceptanceTest`, `CleaningSpecialistReservationGateTest`, `CleaningBookingSessionWorkerChangeTest`, `CleaningOperationalExtrasLifecycleTest`, `RecurringCleaningPauseResumeRetryTest`: **22 passed / 192 assertions** on 2026-10-10.
- Latest targeted closure run with `MultiDayEventAssistanceWorkerSchedulingTest`: **17 passed / 108 assertions** on 2026-10-10.
- PHP syntax validation and `git diff --check` passed on modified backend services.
- These are overlapping test subsets; do not add their test counts as unique coverage.

## Evidence gates to complete

- Test authorization refusal at discovery, acceptance, reassignment and execution for inactive/unapproved rows.
- Test overlap at exact interval boundaries, buffers, simultaneous accepts, worker change, pause, cancellation, and every supported schedule edit.
- Review database indexes and add an appropriate uniqueness/locking strategy for legacy/null session IDs where necessary.
- Record changes in the full 116-row traceability matrix when source SRS is available.
- Verify snapshot/release rollback policy and run Filament permission tests before release.
