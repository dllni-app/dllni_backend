# Supervisor backend implementation status

Branch: `feat/supervisor-backend-20261016`
Worktree: `C:\laragon\www\Dllni\_supervisor_backend_implementation`
Date: 2026-10-16

## Implemented API

All endpoints are prefixed `/api/v1`. Protected endpoints require Sanctum authentication and role/booking scoping.

| Caller | Routes |
|---|---|
| Supervisor | `POST supervisor/auth/login`, `POST supervisor/auth/logout`, `GET supervisor/me`, `GET supervisor/home`, `GET supervisor/bookings`, `GET supervisor/schedule` |
| Supervisor | `GET supervisor/bookings/{id}`, `GET .../attendance`, `GET .../timeline` |
| Supervisor | `POST .../verification/request`, `POST .../verification/confirm`, `GET .../verification/status`, `POST .../start` |
| Supervisor | `POST .../attendance-incidents`, `POST .../incidents`, `GET .../incidents`, `POST .../replacement-requests` |
| Supervisor | `POST .../extensions`, `GET .../extensions`, `POST .../handover-request`, `GET .../handover-status` |
| Supervisor | `POST .../report`, `GET .../reports`, `POST .../media`, `GET .../media`, `GET .../media/{mediaId}`, `DELETE .../media/{mediaId}` |
| Customer | `GET user/cleaning/orders/{id}/supervised/verification/code`, `POST .../handover/decision`, `POST .../extensions/{extension}/decision` |
| Operations | `POST admin/cleaning/supervised/bookings/{id}/supervisor`, `POST admin/cleaning/supervised/extensions/{extension}/decision`, `POST .../bookings/{id}/objection/resolve`, `POST .../replacements/{replacement}/decision` |

New customer booking requests can send `serviceType=supervised_team` to the current cleaning estimate/create API, with at least two workers and a single service period. The backend stores `booking_kind=supervised_team` and calculates estimated end using `ceil(base_work_minutes / worker_count)`. Legacy `open_count` remains separate. Booking creation is feature-gated.

## Provisional verification contract

**Implementation selection (requires product approval before enabling public bookings): Option A.** The supervisor starts verification. The authenticated customer fetches a short-lived six-digit code and provides it to the supervisor; the supervisor submits it. The database stores an HMAC plus application-encrypted code, no plaintext field, and enforces five attempts and ten-minute expiry. The customer must not expose the code over public broadcast channels. A supervisor starts the team only after all accepted workers have arrived and verification succeeded.

## Supervisor state machine

`staffing → verification_pending → verified → in_progress → handover_pending → handover_confirmed → completed`; a customer objection moves to `objected`, and authorized operations can resolve it back to `in_progress`. Final report follows handover confirmation; supervisor actions require active assignment and restrict other supervisors. No per-worker room task tracking is introduced.

## Release flags

- `SUPERVISED_TEAM_ENABLED=false` by default. Enables creation of this fourth service after approvals, staging E2E, and Flutter rollout.
- `SUPERVISED_TEAM_EXTENSION_APPROVAL_ENABLED=false` by default. The extension workflow records supervisor request, authenticated customer consent and operations decision; **do not enable actual approval** before extension quote/payment rules and worker overlap safeguards are finalized. Original booked end remains preserved.
- Additive migrations are `2026_10_16_170000`, `170100`, `170200`; run `php artisan migrate` in approved staging, not `migrate:fresh` on a non-disposable database.
- Run `php artisan db:seed --class=TeamRoleTemplatesSeeder` on staging to create the `Cleaning Supervisor` role (no automatic Filament access).

## Validation evidence

Local isolated SQLite `pest` tests: supervisor lifecycle/scoping, legacy restrictions, readiness, customer code/confirmation, final report, revocation after reassignment, extension consent/ops flow, cross-booking isolation. Legacy multi-worker travel/start/completion regression suite separately passed. See terminal test output for precise counts.

## Important incomplete release items

1. Specification sign-off: challenge direction, partial-team exceptions, report ordering, staff replacement authority, extensions/charges, privacy retention.
2. Full Filament workflow UI for staffing/approvals, worker replacement assignment/overlap and financial settlement integration.
3. Realtime/push notification delivery for every transition, delivery retries, recipient channel authorization, cancellation/absence operational escalations.
4. Private media retention policy, incident privacy consent, uploads under poor connectivity and transport retries.
5. Dedicated mobile client full M01–M24 integration, Android E2E, final 32-case QA matrix, broader customer/event/hourly regression and MySQL CI.
6. Safe migration and rollback verified on actual MySQL staging; no production rollout or branch merge should occur until these gates pass.

## Compatibility notes

Existing worker routes for security-code, start and completion are blocked **only** for supervised team bookings. Legacy ordinary/team cleaning retains its worker-owned lifecycle. Native customer completion/extension handlers are blocked for supervised orders to use the new explicit team approval flow. Booking timers use server-generated timestamps. No automatic supervision surcharge is added.
