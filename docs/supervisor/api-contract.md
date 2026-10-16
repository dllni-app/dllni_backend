# Supervisor mobile API contract

Base URL: `/api/v1/supervisor`. JSON responses use `data`; errors from this API use `code` and Arabic `message` for controlled role, ownership, login, and unconfigured feature responses. Times use ISO 8601 with server offset and `timezone` from `config('app.timezone')` where present. The client should use `serverNow` for clock alignment.

## Routes

These are the 17 routes returned by `php artisan route:list --path=supervisor` on 2026-10-16:

| Method | Path | Behavior |
| --- | --- | --- |
| POST | `/login` | Phone/password login; active `Cleaning Supervisor` only; Sanctum token in `data.token` |
| GET | `/me` | Current supervisor and server time |
| POST | `/logout` | Revoke current token |
| GET | `/home` | Assigned booking count for today and next booking |
| GET | `/bookings` | Active assigned supervised bookings; `status`, `date`, `search`, `perPage` (1-50), standard pagination metadata |
| GET | `/schedule?date=YYYY-MM-DD` | Assigned bookings on date |
| GET | `/bookings/{booking}` | Scoped booking summary and `allowedActions` |
| GET | `/bookings/{booking}/attendance` | Real assignment statuses, timestamps, and strict team readiness |
| GET | `/bookings/{booking}/timeline` | Last 100 supervisor activity records |
| GET | `/bookings/{booking}/incidents` | Paginated private incident records |
| POST | `/bookings/{booking}/attendance-incidents` | Create incident; `type`, `description`, optional scoped `workerAssignmentId` |
| POST | `/bookings/{booking}/replacement-requests` | Record pending request for a scoped worker assignment; no worker change |
| POST | `/bookings/{booking}/verification-requests` | 409 `FEATURE_NOT_CONFIGURED` |
| POST | `/bookings/{booking}/start` | 409 `FEATURE_NOT_CONFIGURED` |
| POST | `/bookings/{booking}/extension-requests` | 409 `FEATURE_NOT_CONFIGURED` |
| POST | `/bookings/{booking}/handover-requests` | 409 `FEATURE_NOT_CONFIGURED` |
| POST | `/bookings/{booking}/reports` | 409 `FEATURE_NOT_CONFIGURED` |

Except login, every route requires `auth:sanctum`, active supervisor role, and an active assignment to any booking ID. A booking belonging to another supervisor, a released assignment, or a different `booking_kind` responds `404 BOOKING_NOT_FOUND`. A non-supervisor responds `403 SUPERVISOR_FORBIDDEN`. Unconfigured actions check ownership before returning 409. No attendance endpoint writes worker arrival. No endpoint marks a booking paid or completed.

The Flutter application should use these exact paths; older draft paths such as `/supervisor/auth/login` or `/bookings/{id}/verification/request` are not part of the registered API. Validation errors use Laravel's normal 422 `errors` object.
