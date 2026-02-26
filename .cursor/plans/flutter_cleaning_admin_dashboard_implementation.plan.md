---
name: Flutter Cleaning Admin Dashboard Implementation
overview: Flutter developer implementation plan for the Cleaning module admin dashboard — UI best practices, data handling, and page-by-page data & flow guide. References cleaning_service_dashboard_sections and API_CONTRACT_CLEANING.
todos: []
isProject: false
---

# Flutter – Cleaning Admin Dashboard Implementation Plan

**Audience:** Flutter developer  
**UI spec:** [cleaning_service_dashboard_sections_0e528c6e.plan.md](.cursor/plans/cleaning_service_dashboard_sections_0e528c6e.plan.md)  
**API contract:** [docs/API_CONTRACT_CLEANING.md](../docs/API_CONTRACT_CLEANING.md)  
**Client behavior:** [docs/API_CONTRACT_CLIENT_BEHAVIOR.md](../docs/API_CONTRACT_CLIENT_BEHAVIOR.md)

---

## 1. Best practices – UI implementation

- **Layout:** Use responsive breakpoints (e.g. single column on mobile, cards + table on tablet/desktop). Prefer `LayoutBuilder` or `ResponsiveBuilder` so overview cards and tables adapt.
- **Loading / empty / error:** Every data-driven screen must have: (1) loading state (shimmer or skeleton), (2) empty state (illustration + CTA if applicable), (3) error state (message + retry). Never show raw IDs or enum values to the user; use select menus and labels only (per client behavior).
- **Status badges:** Use `CleaningBookingStatus` and `EventBookingStatus` enum values from API; display localized labels (e.g. `pending` → "قيد الانتظار"). Keep badge colors consistent (e.g. warning for pending, success for completed, error for cancelled).
- **Safety emphasis:** SOS and “delayed rating” / “time exceeded” alerts must be visually prominent (e.g. red card, optional sound). Use semantic colors and icons.
- **Accessibility:** Sufficient contrast, touch targets ≥ 44pt, support for larger text; label form fields; announce critical alerts to screen readers.
- **RTL:** Support RTL for Arabic: use `Directionality` and `TextDirection` where layout depends on direction; test list and table alignment.

---

## 2. Data handling

- **Auth:** Store token securely; send `Authorization: Bearer {token}` on every request. Base URL: `https://dllni.mustafafares.com`, API prefix: `/api/v1/`.
- **Pagination:** Index endpoints return `data`, `links`, `meta`. Use `perPage` (1–100, default 20), `page`. Implement “load more” or paginated list; preserve scroll position on refresh when possible.
- **Filters & sort:** Send as query params: `filter[fieldName]=value`, `sort=field` or `sort=-field`. Use **camelCase** for sort (e.g. `createdAt`, `scheduledDate`). Filter values (e.g. status) must come from **select menus**; store selected id/enum in page state and send that value — do not show raw id/enum to user.
- **POST:** Backend-known data (e.g. module key, ids from context) must be kept in page/screen state and not shown or editable. One dedicated input per user-supplied field.
- **PUT:** Apply optimistic update: update local state immediately after sending the request; on success keep it; on failure revert and show error (snackbar/dialog).
- **Errors:** Parse 4xx/5xx JSON; show validation errors under `errors` key by field. Show generic message for network/server errors with retry option.
- **Caching/refresh:** Consider short-lived cache for dashboard overview (e.g. 1–2 min) with pull-to-refresh. List pages: refresh on focus or explicit refresh; optional cache for list data with invalidation on create/update/delete.

---

## 3. Page-by-page data & flow guide

### 3.1 Live overview / command center


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                              |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | First screen after login; KPIs + system alerts.                                                                                                                                                                                                                                                                                                                                       |
| **Data to load** | Single request: KPIs (today cleaning/event bookings, open disputes, pending worker assignments, active SOS count) and list of system alerts.                                                                                                                                                                                                                                          |
| **API**          | `GET /api/v1/cleaning/dashboard/overview` (no query params).                                                                                                                                                                                                                                                                                                                          |
| **Flow**         | On load → show loading skeleton → on success render KPI cards + alerts list; on error show error state + retry. Pull-to-refresh re-fetches. Quick links navigate to Cleaning bookings, Event bookings, Workers, Disputes.                                                                                                                                                             |
| **UI**           | Cards: today cleaning bookings, today event bookings, open disputes, pending assignments, active SOS (highlight if > 0). Alerts panel: rows with alert type, booking #, customer/worker names and phones, short description; actions: Call customer, Call worker, Dismiss/Resolve. SOS and safety alerts (delayed rating, frozen location, time exceeded) must be visually prominent. |


---

### 3.2 Cleaning bookings management


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List and manage all cleaning bookings; filters and detail.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| **Data to load** | Paginated list (with filters); on row tap → single booking detail.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| **API**          | List: `GET /api/v1/cleaning-bookings?perPage=20&page=1&sort=-createdAt` + optional `filter[status]`, `filter[scheduledDateFrom]`, `filter[scheduledDateTo]`, `filter[customerId]`, `filter[workerId]`, `filter[hasDispute]`. Detail: `GET /api/v1/cleaning-bookings/{id}`. Assign worker / cancel: `PUT /api/v1/cleaning-bookings/{id}` (or dedicated action endpoints if documented).                                                                                                                                                                     |
| **Flow**         | List: load first page; filters via select menus (status, date range, etc.) — store selected values in state and send as filter params; user does not see raw enum/ids. Infinite scroll or “load more” for next page. Row tap → navigate to detail; detail loads booking with relations (customer, worker, services, add-ons, status timeline). Assign worker: open picker (workers from API), send worker id in PUT body (worker id from state, not editable by user). Cancel: confirm dialog → PUT with status; optimistic update then confirm or revert. |
| **UI**           | Table (or card list on small screens): Booking #, customer, worker or “Unassigned”, scheduled date/time, status badge, total price, actions (View, Assign worker, Cancel). Filters: Status (select), date range (date pickers), customer/worker (search/select). Detail: drawer or page with full booking fields, services/add-ons, status timeline, link to dispute if any.                                                                                                                                                                               |


---

### 3.3 Event bookings management


| Item             | Guidance                                                                                                                                                                                                         |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List and manage event-assistance bookings.                                                                                                                                                                       |
| **Data to load** | Paginated list; detail on tap.                                                                                                                                                                                   |
| **API**          | List: `GET /api/v1/event-bookings?perPage=20&page=1&sort=-createdAt` + `filter[status]`, `filter[eventType]`, `filter[scheduledDateFrom]`, `filter[scheduledDateTo]`. Detail: `GET /api/v1/event-bookings/{id}`. |
| **Flow**         | Same pattern as Cleaning bookings: filters from select menus (status, event type, date range); store values in state; load detail on tap. Use optimistic update for any PUT.                                     |
| **UI**           | Table: Booking #, customer, event type, guest range, scheduled date/time, status, team size, total price, actions. Detail: full fields, services, timeline.                                                      |


---

### 3.4 Workers / service providers management


| Item             | Guidance                                                                                                                                                                                                                                                                               |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List workers; view profile (trust, performance, reviews, zones).                                                                                                                                                                                                                       |
| **Data to load** | Paginated workers list; on “View profile” → worker detail (trust log, performance, reviews, worker-to-customer ratings, preferred zones).                                                                                                                                              |
| **API**          | List: `GET /api/v1/workers?perPage=20&page=1` + filters if available. Profile: `GET /api/v1/workers/{id}` (or dedicated profile endpoint per contract). Suspend/activate: `PUT /api/v1/workers/{id}`.                                                                                  |
| **Flow**         | List loads with optional filters. Profile screen: load worker by id (id from navigation args; do not show to user). Trust score, log, performance metrics, reviews, zones from response. Suspend: confirm → PUT; optimistic update.                                                    |
| **UI**           | List: name, photo, verified badge, trust score, completed tasks, avg rating, status (active/suspended), actions (View profile, Suspend). Profile: trust card (score, change log), performance stats, reviews list, worker→customer ratings, preferred work zones (list of zone names). |


---

### 3.5 Disputes and support tickets


| Item             | Guidance                                                                                                                                                                                                                                               |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | Resolve disputes; view thread and take actions.                                                                                                                                                                                                        |
| **Data to load** | Paginated disputes list; detail with messages and booking summary.                                                                                                                                                                                     |
| **API**          | List: `GET /api/v1/disputes` (or cleaning-scoped disputes endpoint per contract) with filters. Detail: `GET /api/v1/disputes/{id}`. Reply: `POST` to messages; Resolve/Close: `PUT /api/v1/disputes/{id}`.                                             |
| **Flow**         | Filters (status, etc.) from select; open detail → load dispute + messages. Reply: user types in dedicated input → POST; optimistic append of message then confirm. Resolve/Refund/Deduct: confirm → PUT; optimistic update.                            |
| **UI**           | Table: Dispute ID, booking #, customer, worker, reason/category, status, opened at, actions (View, Reply, Resolve). Detail: complaint text, media; worker reply; message thread; booking summary; actions (Refund partial, Deduct from worker, Close). |


---

### 3.6 Pricing and financial settings


| Item             | Guidance                                                                                                                                                                                                                                                                                                        |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Configure pricing, add-ons, travel, billing policy.                                                                                                                                                                                                                                                             |
| **Data to load** | Load cleaning services, service pricing, add-ons, travel config, billing policies (endpoints as in API contract).                                                                                                                                                                                               |
| **API**          | Use endpoints for cleaning services, pricing, add-ons, travel config, billing policies (exact paths from API_CONTRACT_CLEANING).                                                                                                                                                                                |
| **Flow**         | Form screens: backend-known keys (e.g. module, service id) in state only. User-editable fields: one input each (base rate, min hours, per-km rate, etc.). Dropdowns for “distance start point” and “time billing policy” show labels; store enum/value in state and send on submit. PUT with optimistic update. |
| **UI**           | Sections: Basic pricing (hourly rate, min hours), Add-ons list (price, type), Revenue (commission), Travel (per-km, min fee, distance start point radio), Time billing (full time vs actual; min billable minutes), Billing policies list.                                                                      |


---

### 3.7 Coverage by zone


| Item             | Guidance                                                                                                                                                                                     |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Table of zones with worker count and coverage level.                                                                                                                                         |
| **Data to load** | Geographic coverage: zones with worker counts.                                                                                                                                               |
| **API**          | `GET` geographic-coverage (or equivalent) — zones + worker count per zone.                                                                                                                   |
| **Flow**         | Single load; compute coverage level (Low/OK/High) from thresholds (e.g. 0–1 Low, 2–4 OK, 5+ High) in app or use from API. Optional: tap row → drawer with zone name, worker count, coverage. |
| **UI**           | Table: Zone name, Workers covering, Coverage (badge). Sortable. Optional demand card “Bookings last 30 days” if API provides.                                                                |


---

### 3.8 Roles and admin users


| Item             | Guidance                                                                                                                             |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | Manage admin roles and assign users.                                                                                                 |
| **Data to load** | Roles list; admin users list; permissions (from app-level API).                                                                      |
| **API**          | Use app-level roles/permissions endpoints (not Cleaning-specific).                                                                   |
| **Flow**         | List roles; add/edit role and assign permissions. List admin users; invite; assign role (store role id in state for POST/PUT).       |
| **UI**           | Roles table; role detail with permission toggles. Admin users table; invite form; role selector (dropdown with labels, id in state). |


---

### 3.9 System alerts and time-end warnings


| Item             | Guidance                                                                                                             |
| ---------------- | -------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Alerts surfaced in overview (3.1); optional dedicated time-warnings log.                                             |
| **Data to load** | Alerts: from dashboard overview. Time warnings: if endpoint exists, paginated list of `cleaning_time_warnings`.      |
| **API**          | Overview alerts from `GET /api/v1/cleaning/dashboard/overview`. Time warnings: per contract if available.            |
| **Flow**         | Same as 3.1 for alerts. Time-warnings screen: load list; filters by date/booking if supported.                       |
| **UI**           | Alerts: see 3.1. Time-warnings table: Booking #, type (cleaning/event), sent at, customer response, worker response. |


---

## 4. Data ↔ API quick reference (Cleaning)


| Section           | Main API / data                                                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Overview          | `GET /api/v1/cleaning/dashboard/overview`                                                                                |
| Cleaning bookings | `GET/POST/PUT/DELETE /api/v1/cleaning-bookings`, filters: status, scheduledDateFrom/To, customerId, workerId, hasDispute |
| Event bookings    | `GET/POST/PUT/DELETE /api/v1/event-bookings`, filters: status, eventType, scheduledDateFrom/To                           |
| Workers           | `GET /api/v1/workers`, `GET/PUT /api/v1/workers/{id}`                                                                    |
| Disputes          | Disputes list/detail/reply/resolve (cleaning-scoped endpoints per contract)                                              |
| Pricing           | Cleaning services, pricing, add-ons, travel, billing policies (per contract)                                             |
| Coverage          | Geographic coverage (zones + worker counts)                                                                              |
| Roles & admin     | App-level roles/permissions endpoints                                                                                    |


---

## 5. Summary

- **10 main sections;** each as a separate route/screen with loading, empty, and error states.
- **Filters/sort:** Always use select menus for id/enum; store value in state and send in requests; never expose raw id/enum to user.
- **POST:** Backend-known data in state only; one input per user field. **PUT:** Optimistic update; revert on failure.
- **Safety:** SOS and critical alerts (delayed rating, frozen location, time exceeded) must be visually prominent.
- **API:** All requests camelCase; auth header on every call; follow [API_CONTRACT_CLEANING.md](../docs/API_CONTRACT_CLEANING.md) and [API_CONTRACT_CLIENT_BEHAVIOR.md](../docs/API_CONTRACT_CLIENT_BEHAVIOR.md).

