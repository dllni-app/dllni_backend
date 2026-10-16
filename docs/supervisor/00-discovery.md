# Supervisor Backend Discovery

Date: 2026-10-16. Branch: dev. Laravel 12.58.0.

## Inspected

Existing CleaningBooking and CleaningBookingWorkerAssignment models; booking sessions and time-period migrations. Initial Git tree clean, branch one commit ahead of origin/dev.

## Compatibility

Supervised team must be a distinct fourth service. Traditional open_count/room assignment and worker execution are unchanged until explicit supervised-type dispatch is implemented. No per-worker room tasks are allowed for supervised teams.

## Open decisions

1. Verification code direction and challenge policy.
2. Approval/consent for extensions and financial consequences.
3. Report vs customer handover and closure sequence.
4. Supervisor identity/role authority and reassignment permissions.
5. Exceptional partial-team readiness authority.
6. Private media retention and access policy.

## Next gates

Inspect live API contracts, define approved role and verification contract, add schema and scoped services, run migration on isolated test database, implement lifecycle and legacy regression tests. Do not run migrate:fresh against the existing environment.
