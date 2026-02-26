---
name: Flutter Cleaning Admin Dashboard Implementation
overview: Flutter developer implementation plan for the Cleaning module admin dashboard — UI best practices, data handling, and page-by-page data & flow guide. The UI must implement the admin dashboard per the PDF specification (تعديل خدمة التنظيف) and API contracts.
todos: []
isProject: false
---

# Flutter – Cleaning Admin Dashboard Implementation Plan

**Audience:** Flutter developer  
**UI source:** PDF specification (تعديل خدمة التنظيف) — admin dashboard sections 1.1–1.10 below.  
**Dashboard API contract:** API_CONTRACT_CLEANING_DASHBOARD.md

**PDF spec → plan mapping:** 1.1 Live overview → §3.1, §4.1 | 1.2 Cleaning bookings → §3.2, §4.2 | 1.3 Event bookings → §3.3, §4.3 | 1.4 Workers → §3.4, §4.4 | 1.5 Disputes → §3.5, §4.5 | 1.6 Pricing → §3.6, §4.6 | 1.7 Coverage by zone → §3.7, §4.7 | 1.8 Roles → §3.8, §4.8 | 1.9 System alerts → §3.9, §4.9 | 1.10 Time-end warnings → §3.9, §4.9.

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

## 3. UI page components & UX

Suggested Flutter widgets and UX patterns per screen. Reuse shared components (e.g. `KpiCard`, `StatusChip`, `FilterBar`, `DataTableResponsive`) for consistency.

### 3.0 Shared shell & navigation


| Component                                | Use                                                                                                           |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| **Scaffold**                             | Every screen; consistent `appBar`, `drawer`/`endDrawer` for nav.                                              |
| **AppBar**                               | Title, optional actions (refresh, export); back on detail screens.                                            |
| **NavigationRail / BottomNavigationBar** | Module sections (Overview, Bookings, Workers, Disputes, etc.); selected index in state.                       |
| **Drawer**                               | Side nav with section list; highlight current route; RTL-aware.                                               |
| **SnackBar**                             | Success/error after actions (assign worker, cancel, resolve); optional action "Undo" where applicable.        |
| **Dialog / AlertDialog**                 | Confirm destructive actions (cancel booking, suspend worker, close dispute); clear primary/secondary buttons. |


**UX:** Persistent nav so user can jump between sections; breadcrumbs on detail screens; consistent back behavior (pop or navigate to list).

---

### 3.1 Live overview / command center (PDF §1.1)

**From PDF:** KPIs + system alerts. Four **system alert types** (show by type label): (1) Delayed mutual rating (>3h after work end, no rating), (2) Frozen location (worker “on the way” but location unchanged ~20 min), (3) SOS (highest priority; prominent + optional sound), (4) Time exceeded without end (e.g. 15 min past scheduled end, booking still “In progress”). Safety-potential alerts must be visually prominent (e.g. red card). Per-alert actions: Call customer, Call worker, **Send reminder**, Resolve/Dismiss.


| Component                | Use                                                                                                                                                                                                                 |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **RefreshIndicator**     | Wrap scrollable content; on refresh call overview API.                                                                                                                                                              |
| **GridView** or **Wrap** | KPI cards in a 2–4 column grid (responsive); same card widget for each KPI.                                                                                                                                         |
| **Card**                 | Each KPI: icon, label (localized), value, optional trend; tap navigates to relevant list (e.g. "Today cleaning" → cleaning bookings filtered by today).                                                             |
| **ListTile** / **Card**  | Each alert row: leading icon (by `alertType`/`severity`), title (alert type label), subtitle (booking #, short description), trailing actions: **[Call customer] [Call worker] [Send reminder] [Dismiss/Resolve]**. |
| **Chip** / **Badge**     | Severity (e.g. critical = red); SOS row uses distinct background + optional sound.                                                                                                                                  |
| **Skeleton / Shimmer**   | Loading state for KPI grid and alert list.                                                                                                                                                                          |
| **Empty / Error**        | Centered message + retry button if no alerts or on error.                                                                                                                                                           |


**UX:** SOS and high-severity alerts at top or in a separate “Critical” block; quick links as buttons or cards; pull-to-refresh; optional auto-refresh interval for alerts.

---

### 3.2 Cleaning bookings management (PDF §1.2)

**From PDF:** Table: Booking #, customer, worker (or “Unassigned”), scheduled date/time, status (`CleaningBookingStatus`), total price, actions (View, Assign worker, Cancel). Filters: Status, date range, customer, worker, **has dispute**. Detail: full booking fields (property type, estimated sqm/hours, base_price, addons_total, travel_fee, total_price, work_started_at, work_finished_at, customer_confirmed_at), services, add-ons, status timeline, link to dispute if any.


| Component                             | Use                                                                                                                                                                                                                                                                  |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Filter bar**                        | **DropdownButtonFormField** (status – labels only, value in state); **DateRangePicker** or two **TextFormField** + date picker (from/to); optional search/select for customer or worker. Apply filters on change or via “Apply” button.                              |
| **Responsive layout**                 | **LayoutBuilder**: narrow → **ListView** of **Card** (one card per booking); wide → **SingleChildScrollView** + **DataTable** (sortable columns).                                                                                                                    |
| **DataTable**                         | Columns: Booking #, Customer, Worker, Scheduled, Status, Total, Actions. **DataRow** onTap → navigate to detail.                                                                                                                                                     |
| **StatusChip**                        | Map `CleaningBookingStatus` to localized label + color (pending=amber, completed=green, cancelled=red).                                                                                                                                                              |
| **PopupMenuButton** or **IconButton** | Per row: View, Assign worker, Cancel. Assign opens **DropdownButtonFormField** (workers from API; show name, store id).                                                                                                                                              |
| **Detail (drawer/page)**              | **Full booking fields (PDF):** property type, estimated sqm/hours, base_price, addons_total, travel_fee, total_price, work_started_at, work_finished_at, customer_confirmed_at; services list; add-ons list; status timeline; **TextButton** link to dispute if any. |
| **AlertDialog**                       | Confirm cancel: title, message, [Cancel] [Confirm]. On confirm send PUT; optimistic update then snackbar success/error.                                                                                                                                              |


**UX:** Filters above list; loading skeleton for table/cards; empty state “No bookings” + illustration; pagination or “Load more” assign worker in one step (picker → confirm). Include **has dispute** in filters.

---

### 3.3 Event bookings management (PDF §1.3)

**From PDF:** Table: Booking #, customer, event type, guest range, scheduled date/time, status (`EventBookingStatus`), team size, total price, actions. Filters: Status, event type, date range. Detail: full fields, services, timeline (same idea as cleaning).


| Component   | Use                                                                                                                                                                 |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Same as 3.2 | Filter bar (status, **eventType** – labels only, date range); list/table with **StatusChip**; row actions; detail drawer/page with full fields, services, timeline. |
| **Chip**    | Event type (family_dinner, birthday, etc.) as localized label.                                                                                                      |


**UX:** Same patterns as cleaning bookings; event-type filter shows human-readable options only.

---

### 3.4 Workers / service providers management (PDF §1.4)

**From PDF:** List/table: Worker name, photo, verified badge, trust score, completed tasks, avg rating, status (active/suspended), actions (View profile, Suspend, etc.). **Worker profile (admin)** must include: (1) **Trust card** — score (e.g. 85/100) + log of score changes with reasons (e.g. “+5 completed 5-star task”, “-10 cancelled <3h”); (2) **Performance** — total completed tasks, acceptance rate, cancel rate, average rating, open disputes count; (3) **Reviews** — list of customer reviews; (4) **Worker-to-customer ratings** — list of ratings this worker gave to customers; (5) **Preferred work zones** — list of zone names (no map in v1). **Automation (PDF):** In system settings, rules e.g. “If trust < 40 then suspend and notify admin”; “If 50 tasks with rating > 4.8 then badge ‘Featured’ and temporary commission reduction.” Surface in Settings UI if API exposes them.


| Component                     | Use                                                                                                                                                                                                                                                                                                                                                         |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **ListView** or **DataTable** | Rows: **CircleAvatar** (photo), name, verified **Icon**, trust score, completed tasks, rating, status chip, actions (View profile, Suspend).                                                                                                                                                                                                                |
| **Card** (list variant)       | One card per worker; tap → profile screen.                                                                                                                                                                                                                                                                                                                  |
| **Profile screen**            | **Scaffold** + **AppBar**. Sections: **Trust card** (score + log with reasons); **Performance** (completed tasks, acceptance rate, cancel rate, avg rating, open disputes); **Reviews** (ListView); **Worker→customer ratings** (ListView); **Preferred work zones** (list of zone names, no map v1). **ElevatedButton** Suspend + **AlertDialog** confirm. |
| **Skeleton**                  | Loading for list and profile.                                                                                                                                                                                                                                                                                                                               |


**UX:** Worker id only in route/state; suspend requires confirmation and success/error feedback. Automation rules in System/Settings if available from API.

---

### 3.5 Disputes and support tickets (PDF §1.5)

**From PDF:** Table: Dispute ID, booking #, customer, worker, reason/category, status, opened at, actions (View, Reply, Resolve). Detail: Customer complaint text, optional media; worker reply; message thread; booking summary; actions: **Refund partial**, **Deduct from worker**, **Close dispute**.


| Component      | Use                                                                                                                                                                                                                                                                  |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Filter bar** | **DropdownButtonFormField** (status, optional category); list/table of disputes.                                                                                                                                                                                     |
| **Detail**     | **Scaffold**; complaint text, optional **Image**/media; **ListView** of message bubbles (customer vs support); **TextFormField** + **ElevatedButton** for reply; actions: **Refund partial**, **Deduct from worker**, **Close dispute** (each with **AlertDialog**). |
| **Chip**       | Status and category as labels.                                                                                                                                                                                                                                       |


**UX:** Reply sends POST; append message optimistically; resolve/close require confirmation; show booking summary (e.g. collapsible **ExpansionTile**).

---

### 3.6 Pricing and financial settings (PDF §1.6)

**From PDF:** Entry: Main menu → “Financial settings” → “Cleaning & home assistance” → “Manage pricing”. Basic pricing (hourly rate, min hours); Add-ons (fixed or %); Revenue (commission); Travel (per-km, min fee, distance start point: 3 radios); Time billing (full time vs actual + min billable minutes); Billing policies list/create/edit.


| Component                    | Use                                                                                                                                                                                                    |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Form**                     | **TextFormField** (base rate, min hours, per-km, min fee, min billable minutes); **Radio** or **DropdownButtonFormField** for “distance start point” and “time billing” (labels only; value in state). |
| **Navigation**               | Main menu → Financial settings → Cleaning & home assistance → Manage pricing (breadcrumb or nav state).                                                                                                |
| **ListTile** / **DataTable** | Add-ons list (name, price, type: fixed or %); billing policies list. **IconButton** edit/delete per row.                                                                                               |
| **Save**                     | **ElevatedButton** at bottom; on success snackbar; optimistic update for PUT.                                                                                                                          |


**UX:** Group in **Card**s: Basic pricing, Add-ons, Revenue, Travel, Time billing, Billing policies; backend-known keys never shown; validation errors under each field.

---

### 3.7 Coverage by zone (PDF §1.7 – simplified)

**From PDF:** Section name: “Coverage by zone” under analytics. **No map/heatmap in v1.** Coverage table: Zone name | Workers covering | Coverage (Low / OK / High from configurable thresholds, e.g. 0–1 = Low, 2–4 = OK, 5+ = High). Sortable by worker count or name. Optional: “Bookings last 30 days” card or demand summary. Optional: click zone row → drawer/modal: Zone name, Workers covering, Coverage. Worker profile (1.4): show “Preferred work zones” as **list of zone names** (no map in v1). Implementation: use existing `GET` geographic-coverage API; add coverage level in backend or frontend from thresholds; no map library required for v1.


| Component                               | Use                                                                                                        |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| **DataTable** or **ListView**           | Columns: Zone name, Workers covering, Coverage (**Chip**: Low/OK/High). Sortable by column header. No map. |
| **Card** (optional)                     | “Bookings last 30 days” summary if API provides.                                                           |
| **Drawer** / **BottomSheet** (optional) | On row tap: Zone name, Workers covering, Coverage level.                                                   |


**UX:** Single load; coverage level from thresholds in app or API; optional sort by worker count or name. v1: table only, no map.

---

### 3.8 Roles and admin users (PDF §1.8)

**From PDF:** Roles: List of roles (e.g. Super Admin, Cleaning Ops Manager, Customer Support, Onboarding Specialist, Accountant) with permission templates. Admin users: Invite/assign role to each admin user.


| Component                        | Use                                                                                                                       |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| **TabBar** or **DropdownButton** | Switch: Roles                                                                                                             |
| **ListView** / **DataTable**     | Roles: name, permissions summary, actions (Edit). Admin users: name, email, role label, actions (Edit, Invite).           |
| **Form** (role)                  | **TextFormField** (name); **CheckboxListTile** or **SwitchListTile** per permission (label only; permission id in state). |
| **Form** (invite)                | **TextFormField** (email); **DropdownButtonFormField** (role – labels only, id in state).                                 |


**UX:** Role id / permission ids in state only; invite sends POST with selected role id; success snackbar and redirect or refresh list.

---

### 3.9 System alerts and time-end warnings (PDF §1.9, §1.10)

**From PDF §1.9:** Alerts panel (same as 1.1): (1) Delayed mutual rating, (2) Frozen location, (3) SOS (prominent + sound), (4) Time exceeded without end. **Actions per alert:** Call customer, Call worker, **Send reminder**, Resolve/Dismiss.

**From PDF §1.10 (Time-end warnings):** Optional admin view. Table/log: Booking #, type (cleaning/event), sent at, **customer response** (Extend / Commit / Finish early), **worker response**. Useful for support when there are billing disagreements.


| Component     | Use                                                                                                                                                                                                                                                                       |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Alerts        | Reuse overview alert row (3.1); dedicated screen = **ListView** of same **Card**/**ListTile**; filter bar (status, type, severity). Actions: **[Call customer] [Call worker] [Send reminder] [Dismiss/Resolve]**. **AlertDialog** or **BottomSheet** for Dismiss/Resolve. |
| Time warnings | **DataTable** or **ListView**: Booking #, type (cleaning/event), sent at, customer response (Extend / Commit / Finish early), worker response. Optional date filter.                                                                                                      |


**UX:** Alerts list with same severity styling; dismiss/resolve updates status (PUT) and removes or updates row optimistically. Time-warnings table for support transparency.

---

### 3.10 Reusable components checklist


| Component                           | Purpose                                                                    |
| ----------------------------------- | -------------------------------------------------------------------------- |
| **KpiCard**                         | Icon + label + value; optional onTap.                                      |
| **StatusChip**                      | Map enum to localized label + color.                                       |
| **FilterBar**                       | Wrap **DropdownButtonFormField**s and date pickers; emit filter state.     |
| **DataTableResponsive**             | DataTable on wide; ListView of Card on narrow.                             |
| **DetailDrawer**                    | End drawer with booking/worker/dispute detail.                             |
| **ConfirmDialog**                   | Title, message, Cancel, Confirm (destructive style for dangerous actions). |
| **SkeletonGrid** / **SkeletonList** | Loading placeholders.                                                      |
| **EmptyState**                      | Illustration + message + optional CTA.                                     |
| **ErrorView**                       | Message + Retry button.                                                    |


**UX:** Consistent spacing (e.g. 16/24); touch targets ≥ 44pt; loading → content or empty/error; confirm before destructive actions; success/error feedback via SnackBar or in-line.

---

## 4. Page-by-page data & flow guide

Sections below implement the **PDF spec (تعديل خدمة التنظيف)** admin dashboard 1.1–1.10. See §3 for UI components and PDF “From PDF” notes per screen.

### 4.1 Live overview / command center (PDF §1.1)


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | First screen after login; KPIs + system alerts.                                                                                                                                                                                                                                                                                                                                                                                                                                |
| **Data to load** | Single request: KPIs (today cleaning/event bookings, open disputes, pending worker assignments, active SOS count) and list of system alerts. Optional: today’s revenue.                                                                                                                                                                                                                                                                                                        |
| **API**          | `GET /api/v1/cleaning/dashboard/overview` (no query params).                                                                                                                                                                                                                                                                                                                                                                                                                   |
| **Flow**         | On load → show loading skeleton → on success render KPI cards + alerts list; on error show error state + retry. Pull-to-refresh re-fetches. Quick links navigate to Cleaning bookings, Event bookings, Workers, Disputes.                                                                                                                                                                                                                                                      |
| **UI**           | Cards: today cleaning bookings, today event bookings, open disputes, pending assignments, active SOS (highlight if > 0). Alerts panel: **four alert types** (Delayed mutual rating, Frozen location, SOS, Time exceeded without end); each row: alert type, booking #, customer/worker names and phones, short description; actions: **Call customer, Call worker, Send reminder, Dismiss/Resolve**. SOS and safety alerts visually prominent (e.g. red card, optional sound). |


---

### 4.2 Cleaning bookings management


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List and manage all cleaning bookings; filters and detail.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| **Data to load** | Paginated list (with filters); on row tap → single booking detail.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| **API**          | List: `GET /api/v1/cleaning-bookings?perPage=20&page=1&sort=-createdAt` + optional `filter[status]`, `filter[scheduledDateFrom]`, `filter[scheduledDateTo]`, `filter[customerId]`, `filter[workerId]`, `filter[hasDispute]`. Detail: `GET /api/v1/cleaning-bookings/{id}`. Assign worker / cancel: `PUT /api/v1/cleaning-bookings/{id}` (or dedicated action endpoints if documented).                                                                                                                                                                     |
| **Flow**         | List: load first page; filters via select menus (status, date range, etc.) — store selected values in state and send as filter params; user does not see raw enum/ids. Infinite scroll or “load more” for next page. Row tap → navigate to detail; detail loads booking with relations (customer, worker, services, add-ons, status timeline). Assign worker: open picker (workers from API), send worker id in PUT body (worker id from state, not editable by user). Cancel: confirm dialog → PUT with status; optimistic update then confirm or revert. |
| **UI**           | Table (or card list on small screens): Booking #, customer, worker or “Unassigned”, scheduled date/time, status badge, total price, actions (View, Assign worker, Cancel). Filters: Status, date range, customer, worker, **has dispute**. Detail (drawer/page): full booking fields (property type, estimated sqm/hours, base_price, addons_total, travel_fee, total_price, work_started_at, work_finished_at, customer_confirmed_at), services, add-ons, status timeline, link to dispute if any.                                                        |


---

### 4.3 Event bookings management


| Item             | Guidance                                                                                                                                                                                                         |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List and manage event-assistance bookings.                                                                                                                                                                       |
| **Data to load** | Paginated list; detail on tap.                                                                                                                                                                                   |
| **API**          | List: `GET /api/v1/event-bookings?perPage=20&page=1&sort=-createdAt` + `filter[status]`, `filter[eventType]`, `filter[scheduledDateFrom]`, `filter[scheduledDateTo]`. Detail: `GET /api/v1/event-bookings/{id}`. |
| **Flow**         | Same pattern as Cleaning bookings: filters from select menus (status, event type, date range); store values in state; load detail on tap. Use optimistic update for any PUT.                                     |
| **UI**           | Table: Booking #, customer, event type, guest range, scheduled date/time, status, team size, total price, actions. Detail: full fields, services, timeline.                                                      |


---

### 4.4 Workers / service providers management


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List workers; view profile (trust, performance, reviews, zones).                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| **Data to load** | Paginated workers list; on “View profile” → worker detail (trust log, performance, reviews, worker-to-customer ratings, preferred zones).                                                                                                                                                                                                                                                                                                                                                                   |
| **API**          | List: `GET /api/v1/workers?perPage=20&page=1` + filters if available. Profile: `GET /api/v1/workers/{id}` (or dedicated profile endpoint per contract). Suspend/activate: `PUT /api/v1/workers/{id}`.                                                                                                                                                                                                                                                                                                       |
| **Flow**         | List loads with optional filters. Profile screen: load worker by id (id from navigation args; do not show to user). Trust score, log, performance metrics, reviews, zones from response. Suspend: confirm → PUT; optimistic update.                                                                                                                                                                                                                                                                         |
| **UI**           | List: name, photo, verified badge, trust score, completed tasks, avg rating, status (active/suspended), actions (View profile, Suspend). Profile (PDF §1.4): Trust card (score e.g. 85/100 + log with reasons); Performance (completed tasks, acceptance rate, cancel rate, avg rating, open disputes); Reviews list; Worker→customer ratings list; Preferred work zones (list of zone names, no map v1). Automation rules (e.g. trust < 40 suspend, 50 tasks > 4.8 → Featured) in Settings if API exposes. |


---

### 4.5 Disputes and support tickets


| Item             | Guidance                                                                                                                                                                                                                                                                                               |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | Resolve disputes; view thread and take actions.                                                                                                                                                                                                                                                        |
| **Data to load** | Paginated disputes list; detail with messages and booking summary.                                                                                                                                                                                                                                     |
| **API**          | List: `GET /api/v1/disputes` (or cleaning-scoped disputes endpoint per contract) with filters. Detail: `GET /api/v1/disputes/{id}`. Reply: `POST` to messages; Resolve/Close: `PUT /api/v1/disputes/{id}`.                                                                                             |
| **Flow**         | Filters (status, etc.) from select; open detail → load dispute + messages. Reply: user types in dedicated input → POST; optimistic append of message then confirm. Resolve/Refund/Deduct: confirm → PUT; optimistic update.                                                                            |
| **UI**           | Table: Dispute ID, booking #, customer, worker, reason/category, status, opened at, actions (View, Reply, Resolve). Detail: complaint text, optional media; worker reply; message thread; booking summary; actions: **Refund partial**, **Deduct from worker**, **Close dispute** (each with confirm). |


---

### 4.6 Pricing and financial settings


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                             |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Configure pricing, add-ons, travel, billing policy.                                                                                                                                                                                                                                                                                                  |
| **Data to load** | Load cleaning services, service pricing, add-ons, travel config, billing policies (endpoints as in API contract).                                                                                                                                                                                                                                    |
| **API**          | Use endpoints for cleaning services, pricing, add-ons, travel config, billing policies (exact paths from API_CONTRACT_CLEANING).                                                                                                                                                                                                                     |
| **Flow**         | Form screens: backend-known keys (e.g. module, service id) in state only. User-editable fields: one input each (base rate, min hours, per-km rate, etc.). Dropdowns for “distance start point” and “time billing policy” show labels; store enum/value in state and send on submit. PUT with optimistic update.                                      |
| **UI**           | **Entry (PDF):** Main menu → Financial settings → Cleaning & home assistance → Manage pricing. Sections: Basic pricing (hourly rate, min hours), Add-ons (fixed or %), Revenue (commission), Travel (per-km, min fee, distance start point: 3 radios), Time billing (full time vs actual + min billable minutes), Billing policies list/create/edit. |


---

### 4.7 Coverage by zone


| Item             | Guidance                                                                                                                                                                                                                                                                                                |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Table of zones with worker count and coverage level.                                                                                                                                                                                                                                                    |
| **Data to load** | Geographic coverage: zones with worker counts.                                                                                                                                                                                                                                                          |
| **API**          | `GET` geographic-coverage (or equivalent) — zones + worker count per zone.                                                                                                                                                                                                                              |
| **Flow**         | Single load; compute coverage level (Low/OK/High) from thresholds (e.g. 0–1 Low, 2–4 OK, 5+ High) in app or use from API. Optional: tap row → drawer with zone name, worker count, coverage.                                                                                                            |
| **UI**           | **Coverage by zone (PDF §1.7 – simplified, no map v1):** Table: Zone name, Workers covering, Coverage (Low/OK/High from thresholds). Sortable. Optional: “Bookings last 30 days” card; zone row tap → drawer (Zone, Workers covering, Coverage). Worker profile: preferred zones as list of names only. |


---

### 4.8 Roles and admin users


| Item             | Guidance                                                                                                                             |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | Manage admin roles and assign users.                                                                                                 |
| **Data to load** | Roles list; admin users list; permissions (from app-level API).                                                                      |
| **API**          | Use app-level roles/permissions endpoints (not Cleaning-specific).                                                                   |
| **Flow**         | List roles; add/edit role and assign permissions. List admin users; invite; assign role (store role id in state for POST/PUT).       |
| **UI**           | Roles table; role detail with permission toggles. Admin users table; invite form; role selector (dropdown with labels, id in state). |


---

### 4.9 System alerts and time-end warnings


| Item             | Guidance                                                                                                                                                                                                                                      |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Alerts surfaced in overview (4.1); optional dedicated time-warnings log.                                                                                                                                                                      |
| **Data to load** | Alerts: from dashboard overview. Time warnings: if endpoint exists, paginated list of `cleaning_time_warnings`.                                                                                                                               |
| **API**          | Overview alerts from `GET /api/v1/cleaning/dashboard/overview`. Time warnings: per contract if available.                                                                                                                                     |
| **Flow**         | Same as 4.1 for alerts. Time-warnings screen: load list; filters by date/booking if supported.                                                                                                                                                |
| **UI**           | Alerts (PDF §1.9): same as 4.1; four types; actions include **Send reminder**. Time-end warnings (PDF §1.10): table — Booking #, type (cleaning/event), sent at, **customer response** (Extend / Commit / Finish early), **worker response**. |


---

## 5. Data ↔ API quick reference (Cleaning)


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

## 6. API implementation with example data

Full request/response shapes and enums are in [API_CONTRACT_CLEANING_DASHBOARD.md](../docs/API_CONTRACT_CLEANING_DASHBOARD.md). Below are **example requests and response payloads** for each dashboard area so the Flutter app can type responses and build UI from real structures.

**Base:** `https://dllni.mustafafares.com/api/v1/`  
**Headers:** `Authorization: Bearer {token}`, `Accept: application/json`, `Content-Type: application/json` for POST/PUT.

---

### 5.1 Dashboard overview

**Request:**

```
GET /api/v1/cleaning/dashboard/overview
```

**Example response (200):**

```json
{
  "kpis": {
    "todayCleaningBookings": 12,
    "todayEventBookings": 3,
    "openDisputes": 2,
    "pendingWorkerAssignments": 5,
    "activeSosCount": 0
  },
  "alerts": [
    {
      "id": 1,
      "alertType": "delayed_rating",
      "severity": "high",
      "status": "new",
      "booking": {
        "id": 42,
        "bookingNumber": "CLN-2025-0042",
        "customer": { "id": 10, "name": "Customer A", "phone": "+962790000010" },
        "worker": { "id": 5, "firstName": "Worker X", "phone": "+962790000005" }
      },
      "description": "No mutual rating >3h after work end",
      "createdAt": "2025-02-21T14:00:00.000000Z"
    },
    {
      "id": 2,
      "alertType": "sos_triggered",
      "severity": "critical",
      "status": "new",
      "booking": { "id": 43, "bookingNumber": "CLN-2025-0043", "customer": { "id": 11, "name": "Customer B", "phone": "+962790000011" }, "worker": { "id": 6, "firstName": "Worker Y", "phone": "+962790000006" } },
      "description": "SOS pressed by worker",
      "createdAt": "2025-02-21T15:30:00.000000Z"
    }
  ]
}
```

**Flutter:** Parse `kpis` for KPI cards; parse `alerts` for the alerts list. Use `alertType` and `severity` for styling (e.g. `sos_triggered` / `critical` → red, sound). Enums: `AlertType`, `AlertSeverity`, `SystemAlertStatus` (see contract Section 5).

---

### 5.2 Cleaning bookings list (with filters)

**Request:**

```
GET /api/v1/cleaning-bookings?perPage=10&page=1&sort=-createdAt&filter[status]=confirmed&filter[scheduledDateFrom]=2025-02-01&filter[scheduledDateTo]=2025-02-28
```

**Example response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "bookingNumber": "CLN-2025-0001",
      "status": "confirmed",
      "scheduledDate": "2025-02-15",
      "scheduledTime": "10:00",
      "totalPrice": 150.00,
      "customer": { "id": 10, "name": "Customer A", "email": "customer@example.com", "phone": "+962790000010" },
      "worker": { "id": 5, "firstName": "Worker X" },
      "createdAt": "2025-02-01T10:00:00.000000Z",
      "updatedAt": "2025-02-01T10:00:00.000000Z"
    }
  ],
  "links": {
    "first": "https://dllni.mustafafares.com/api/v1/cleaning-bookings?page=1",
    "last": "https://dllni.mustafafares.com/api/v1/cleaning-bookings?page=5",
    "prev": null,
    "next": "https://dllni.mustafafares.com/api/v1/cleaning-bookings?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "https://dllni.mustafafares.com/api/v1/cleaning-bookings",
    "per_page": 10,
    "to": 10,
    "total": 48
  }
}
```

**Flutter:** Filter values must come from select menus (e.g. status from `CleaningBookingStatus`: `pending`, `confirmed`, `worker_assigned`, `worker_on_the_way`, `worker_arrived`, `in_progress`, `completed`, `cancelled`). Store selected value in state; send as `filter[status]`, etc. Use `meta` for pagination UI.

---

### 5.3 Cleaning booking detail (show)

**Request:**

```
GET /api/v1/cleaning-bookings/1
```

**Example response (200):** Single resource in `data`; include relations when loaded (e.g. `customer`, `worker`, `services`, `addons`, `billingPolicy`, `timeWarnings`, `disputes`). Fields include `propertyType`, `propertyDetails`, `estimatedSqm`, `estimatedHours`, `basePrice`, `addonsTotal`, `travelFee`, `totalPrice`, `workStartedAt`, `workFinishedAt`, `startedTravelAt`, `customerConfirmedAt`, `cancelledAt`, `cancellationReason`, etc. (camelCase; see CleaningBookingResource).

---

### 5.4 Event bookings list (with filters)

**Request:**

```
GET /api/v1/event-bookings?perPage=10&page=1&sort=-createdAt&filter[status]=pending&filter[eventType]=family_dinner&filter[scheduledDateFrom]=2025-02-01&filter[scheduledDateTo]=2025-02-28
```

**Response:** Same paginated shape as cleaning bookings (`data`, `links`, `meta`). Each item: `id`, `status` (EventBookingStatus), `eventType` (EventType), `scheduledDate`, `scheduledTime`, `guestCountMin`/`guestCountMax`, `suggestedTeamSize`, `totalPrice`, `customer`, etc. **EventType** values: `family_dinner`, `birthday`, `large_gathering`, `funeral`, `other`.

---

### 5.5 Workers list and profile

**List request:**

```
GET /api/v1/workers?perPage=20&page=1&filter[isActive]=true&filter[trustScoreMin]=50&filter[search]=ahmed
```

**Response:** Paginated `data` with worker objects (e.g. `id`, `firstName`, `user`, `trustScore`, `completedTasksCount`, `averageRating`, `isActive`, `zones`). Use for worker picker and list screen.

**Profile request:**

```
GET /api/v1/workers/5
```

**Response (200):** `{ "data": { ...worker with user, zones, availability, trustLogs, reviews, ... } }`. Use for worker profile screen (trust card, performance, reviews, preferred zones).

---

### 5.6 Disputes list and detail

**List request:**

```
GET /api/v1/disputes?perPage=20&page=1&filter[status]=open&filter[bookingType]=cleaning_booking
```

**Response:** Paginated; each dispute: `id`, `status` (DisputeStatus: `open`, `under_review`, `resolved`, `closed`), `category` (DisputeCategory), `booking` (morph), `createdAt`. **Detail:** `GET /api/v1/disputes/{id}` returns dispute with `messages` and booking summary. **Reply:** POST to messages endpoint; **Resolve/Close:** PUT dispute with `status`.

---

### 5.7 Cleaning time warnings (admin log)

**Request:**

```
GET /api/v1/cleaning-time-warnings?perPage=20&page=1&filter[bookingId]=1&filter[sentAtFrom]=2025-02-01
```

**Response:** Paginated list of time-warning records (booking #, type cleaning/event, sentAt, customerResponse, workerResponse, etc.). Use for Time-end warnings admin view (Section 3.9).

---

### 5.8 Geographic coverage

**Request:**

```
GET /api/v1/cleaning/analytics/geographic-coverage
```

**Response:** Structure defined by backend (zone-based metrics). Flutter: expect a list of zones with worker count (or similar); compute coverage level (Low/OK/High) from thresholds if not provided.

---

### 5.9 System alerts (dedicated list)

**Request:**

```
GET /api/v1/system-alerts?perPage=20&page=1&filter[status]=new&filter[alertType]=delayed_rating&filter[severity]=high
```

**Response:** Paginated; each alert has `id`, `alertType`, `severity`, `status`, `booking`, description, etc. **Update (dismiss/resolve):** `PUT /api/v1/system-alerts/{id}` with `status` (e.g. `acknowledged`, `resolved`).

---

### 5.10 Validation error (4xx)

**Example response (422):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": ["The selected status is invalid."],
    "scheduledDateFrom": ["The scheduled date from must be a valid date."]
  }
}
```

**Flutter:** Show field-level errors from `errors`; use `message` for generic snackbar/dialog. On 401, redirect to login; on 403/404, show forbidden/not-found message.

---

## 7. Summary

- **UI implements the PDF specification (تعديل خدمة التنظيف)** admin dashboard: 10 sections (1.1 Live overview, 1.2 Cleaning bookings, 1.3 Event bookings, 1.4 Workers, 1.5 Disputes, 1.6 Pricing, 1.7 Coverage by zone, 1.8 Roles, 1.9 System alerts, 1.10 Time-end warnings). See §3 and §4 for component and data alignment per section.
- **10 main sections;** each as a separate route/screen with loading, empty, and error states.
- **Filters/sort:** Always use select menus for id/enum; store value in state and send in requests; never expose raw id/enum to user.
- **POST:** Backend-known data in state only; one input per user field. **PUT:** Optimistic update; revert on failure.
- **Safety:** SOS and critical alerts (delayed rating, frozen location, time exceeded) must be visually prominent.
- **API:** All requests camelCase; auth header on every call; follow [API_CONTRACT_CLEANING.md](../docs/API_CONTRACT_CLEANING.md), [API_CONTRACT_CLEANING_DASHBOARD.md](../docs/API_CONTRACT_CLEANING_DASHBOARD.md), and [API_CONTRACT_CLIENT_BEHAVIOR.md](../docs/API_CONTRACT_CLIENT_BEHAVIOR.md).

