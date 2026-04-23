# API contract – Cleaning module (booking lifecycle, security code, realtime)

**Scope:** HTTP routes registered by the **Cleaning** module only, under **`/api/v1/`**.  
**Auth:** Laravel Sanctum — `Authorization: Bearer {token}` on all protected routes below.  
**Audience:** Flutter (worker app) and any client calling these endpoints directly.

**Out of scope for this file:** User-app routes such as `/api/v1/user/cleaning/orders/...` (customer verification and completion gates). Those live in the User module — see [API_CONTRACT_USER_CLEANING_REALTIME_GATES.md](API_CONTRACT_USER_CLEANING_REALTIME_GATES.md).

**Related:** Full worker-app narrative — [API_CONTRACT_CLEANING_WORKER.md](API_CONTRACT_CLEANING_WORKER.md).

---

## 1. Conventions

- **JSON:** camelCase keys in responses (Laravel API resources).
- **Path parameter:** `{id}` = cleaning booking **numeric id** (`cleaning_booking`).
- **Worker:** Booking actions require an authenticated user with `user.worker`. Missing worker → **403**.

---

## 2. Cleaning booking resource (worker)

| Method | Path | Description |
| ------ | ---- | ----------- |
| GET | `/api/v1/cleaning-bookings` | List bookings (paginated; filters per `API_CONTRACT_CLEANING_WORKER.md`) |
| GET | `/api/v1/cleaning-bookings/{id}` | Show one booking |
| POST | `/api/v1/cleaning-bookings` | Create (admin/ops; not typical worker flow) |
| PUT/PATCH | `/api/v1/cleaning-bookings/{id}` | Update |
| DELETE | `/api/v1/cleaning-bookings/{id}` | Delete |

---

## 3. Booking lifecycle (worker) — Cleaning module only

| Method | Path | Request body | Success |
| ------ | ---- | -------------- | ------- |
| POST | `/api/v1/cleaning-bookings/{id}/accept` | — | `200` — `CleaningBookingResource` |
| POST | `/api/v1/cleaning-bookings/{id}/reject` | `{ "reason": "optional string" }` optional | `200` — booking resource |
| POST | `/api/v1/cleaning-bookings/{id}/start-travel` | — | `200` — `startedTravelAt` set; status stays `worker_assigned` |
| POST | `/api/v1/cleaning-bookings/{id}/location` | `{ "latitude": number, "longitude": number }` | `200` — `{ "data": { "ok": true } }` |
| POST | `/api/v1/cleaning-bookings/{id}/arrive` | — | `200` — status **`awaiting_start_verification`**, `arrivedAt` set |
| GET | `/api/v1/cleaning-bookings/{id}/security-code` | — | `200` — see §4 |
| POST | `/api/v1/cleaning-bookings/{id}/start-work` | — | `200` — status **`in_progress`** (see §5) |
| POST | `/api/v1/cleaning-bookings/{id}/complete` | — | `200` — status **`awaiting_customer_completion`**, `workFinishedAt` set |
| POST | `/api/v1/cleaning-bookings/{id}/cancel` | `{ "reason": "optional" }` optional | `200` — `cancelled` |

**Errors (typical):** `403` (not assigned worker / no worker), `422` (`status` message in `errors`), `404` if booking missing.

---

## 4. Security code (Cleaning module)

**GET** `/api/v1/cleaning-bookings/{id}/security-code`

- **Caller:** Assigned **worker** only.
- **Valid booking statuses:** `worker_assigned`, `awaiting_start_verification`.
- **Response `200`:**

```json
{
  "message": "…",
  "data": {
    "securityCode": "0123",
    "expiresAt": "2026-04-22T12:00:00+00:00"
  }
}
```

| Field | Type | Description |
| ----- | ---- | ----------- |
| `securityCode` | string | **4** digits (`0000`–`9999`), zero-padded. Show to the customer to type in the **user** app (not this module). |
| `expiresAt` | string | ISO 8601; typically **10 minutes** from generation. |

Each successful call **issues a new** code (hash stored server-side; plaintext only in this response).

**Errors:** `403`, `422` (wrong status for issuing a code).

---

## 5. `start-work` behaviour (Cleaning module)

- From **`worker_assigned`:** transitions to **`in_progress`** without customer code (legacy).
- From **`awaiting_start_verification`:** allowed only if the customer has already verified the code (stored row consumed). If the customer already confirmed via the User module, the booking is usually **`in_progress`** already — then `start-work` is not applicable.

---

## 6. Status values (relevant strings on booking `status`)

| Value | Meaning (short) |
| ----- | ---------------- |
| `pending` | Not yet assigned |
| `worker_assigned` | Worker assigned; may be en route |
| `awaiting_start_verification` | After **arrive**; waiting for customer code (User module) |
| `in_progress` | Work in progress |
| `awaiting_customer_completion` | After worker **complete**; customer resolves in User module |
| `time_extension_requested` | Set from customer flow (User module) |
| `completed` | Finished |
| `cancelled` | Cancelled |

---

## 7. Realtime — broadcasts emitted by Cleaning module events

Subscribe as **Pusher** private channel: **`private-cleaning-booking.{bookingId}`** (Laravel channel `cleaning-booking.{id}`).

Authorize: **`POST /broadcasting/auth`** with Sanctum token; `channel_name` = `private-cleaning-booking.{id}`.

| Event name (client) | When |
| --------------------- | ---- |
| `CleaningBookingTrackingUpdated` | Status/timestamp changes (accept, travel, arrive, start-work, complete, cancel, etc.) |
| `WorkerLocationUpdated` | After `POST …/location` |
| `WorkerArrived` | After `POST …/arrive` |
| `cleaning_order.awaiting_start_verification` | After arrive; payload includes `expiresAt` hint for code |
| `cleaning_order.awaiting_customer_completion` | After worker `POST …/complete` |

Some events also target **`private-cleaning-worker.{workerId}`** (e.g. `ArrivalVerified`, `CompletionDecisionMade` — emitted when the **customer** completes actions in the User module; worker app may listen for UI sync).

Payload shapes: [API_CONTRACT_CLEANING_WORKER.md](API_CONTRACT_CLEANING_WORKER.md) §3.7 and [API_CONTRACT_USER_CLEANING_REALTIME_GATES.md](API_CONTRACT_USER_CLEANING_REALTIME_GATES.md) §4 (for cross-app events).

---

## 8. Other Cleaning `v1` routes (reference)

Under **`auth:sanctum`** (not exhaustive — see module `Modules/Cleaning/routes/api.php`):

| Area | Examples |
| ---- | -------- |
| Worker dashboard | `GET /api/v1/cleaning/worker/homepage`, `GET /api/v1/cleaning/worker/statistics`, `GET /api/v1/cleaning/worker/profile` |
| Worker account | `GET/PUT …/cleaning/worker/account/...` |
| Config | `GET …/cleaning-services`, `GET …/cleaning-billing-policies`, pricing nested routes |
| Time warnings | `GET …/cleaning-time-warnings`, `POST …/cleaning-time-warnings/{id}/accept|reject` |

---

## 9. Flutter (worker app) — minimal notes

- Use the same Pusher cluster and **`/broadcasting/auth`** pattern as the rest of the app.
- Bind event names **exactly** as in §7; dotted names (`cleaning_order.…`) must match the listener string.
- After each successful booking action, prefer using the response `data` as source of truth for `status` and timestamps.

---

_End of Cleaning module contract (this file)._
