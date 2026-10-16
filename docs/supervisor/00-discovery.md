# Supervisor backend discovery

Checked on 2026-10-16 on `dev` in `Dllni_backend`. The branch was two commits ahead of `origin/dev` at inspection. Five untracked supervisor implementation files were already present. Additional backend edits appeared during this work, so those edits are listed separately in the implementation status and are not treated as approved behavior.

## Existing behavior

- Laravel 12, PHP 8.4, Sanctum 4 and Spatie Permission 7. The existing password login uses phone and password and issues a Sanctum token. `users.is_active` is the active account flag.
- `cleaning_bookings.booking_kind` already exists, with default `standard`. `number_of_workers` is independent of service type. `supervised_team` is the explicit discriminator for this API.
- The committed supervisor foundation adds `cleaning_supervisor_assignments` and `cleaning_supervisor_activity`. This work adds an active slot uniqueness constraint and incident/request tables through an additive migration. No development database migration was run.
- `cleaning_booking_worker_assignments` has canonical status, `accepted_at`, `started_travel_at`, and `arrived_at`. Accepted statuses come from `CleaningBookingWorkerAssignmentStatus::acceptedValues()`. Readiness requires the configured number of accepted and arrived assignments.
- Legacy cleaning has worker room assignment, security code, completion, and open time flows. Their PRD/SDD rules are specific to traditional cleaning and do not define supervised team verification or customer consent.
- Before implementation, `php artisan route:list --path=supervisor` returned no routes. The current 17-route list is in `api-contract.md`.

## Unresolved business decisions

1. Customer and operations creation or conversion of a supervised booking, including pricing and eligibility. No production activation endpoint is enabled.
2. Supervisor role provisioning and audited assignment authority. The database enforces one active supervisor, but the mobile API cannot assign one.
3. Verification challenge direction, delivery, expiration, retry limits, and customer approval. No code is generated or accepted.
4. Whether a partial team can start and who may approve it. Current readiness is strict: all required workers arrived.
5. Extension price, consent, and schedule consequences; handover and customer confirmation; report order and retention; private media storage and access policy.
6. Existing traditional cleaning routes need a complete discriminator audit before supervised bookings are enabled for real customers. The new supervisor API never uses room assignment or room completion.
