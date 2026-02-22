---
name: Cleaning Service Dashboard Sections
overview: UI developer–oriented specification of admin dashboard sections for the cleaning service, derived from the ERD and requirements PDF.
todos: []
isProject: false
---

# Cleaning Service Admin Dashboard Sections (UI Developer Spec)

This plan defines **admin dashboard sections** for the cleaning service so a UI developer can implement layouts, sections, and placeholders. Data sources: [cleaning_service_erd_231c9672.plan.md](.cursor/plans/cleaning_service_erd_231c9672.plan.md); behaviour from the requirements PDF (تعديل خدمة التنظيف).

---

## Admin dashboard sections

### 1.1 Live overview / command center

- **Purpose:** High-level KPIs and live alerts.
- **Data (from ERD):** Counts from `cleaning_bookings`, `event_bookings` (by status), `disputes`, `sos_alerts`, `system_alerts`; optional today’s revenue from booking totals.
- **UI:**
  - **Cards:** Today’s cleaning bookings, today’s event bookings, open disputes, pending worker assignments, active SOS count.
  - **System alerts panel:** List of **System Alerts** (from PDF): “Delayed mutual rating”, “Frozen location”, “SOS”, “Time exceeded without end”. Each row: alert type, booking #, customer/worker names and phones, short description, actions: [Call customer] [Call worker] [Dismiss/Resolve].
- **Notes:** SOS and “safety potential” alerts (e.g. delayed rating >3h) must be visually prominent (e.g. red card / sound).

---

### 1.2 Cleaning bookings management

- **Purpose:** List and manage all cleaning bookings.
- **Data:** `cleaning_bookings` (with relations: customer, worker, `cleaning_booking_service`, `booking_addons`, status logs).
- **UI:**
  - **Table:** Booking #, customer, worker (or “Unassigned”), scheduled date/time, status (`CleaningBookingStatus`), total price, actions (View, Assign worker, Cancel).
  - **Filters:** Status, date range, customer, worker, has dispute.
  - **Detail view (drawer/page):** Full booking fields (property type, estimated sqm/hours, base_price, addons_total, travel_fee, total_price, work_started_at, work_finished_at, customer_confirmed_at), list of services and add-ons, status timeline, link to dispute if any.

---

### 1.3 Event bookings management

- **Purpose:** List and manage event-assistance bookings.
- **Data:** `event_bookings` (with `event_booking_service`), customer, cancellation_policy, billing_policy.
- **UI:**
  - **Table:** Booking #, customer, event type, guest range, scheduled date/time, status (`EventBookingStatus`), team size, total price, actions.
  - **Filters:** Status, event type, date range.
  - **Detail view:** Same idea as cleaning booking: full fields, services, timeline.

---

### 1.4 Workers / service providers management

- **Purpose:** List workers, view profile, trust score, performance, preferred zones.
- **Data:** `workers`, `worker_zones`, `worker_availability`, `worker_trust_logs`, `booking_reviews` (for worker), `worker_customer_ratings` (given by worker); from bookings: completed count, acceptance/cancel rates.
- **UI:**
  - **List/table:** Worker name, photo, verified badge, trust score, completed tasks, avg rating, status (active/suspended), actions (View profile, Suspend, etc.).
  - **Worker profile (admin view):**
    - **Trust card:** Score (e.g. 85/100), log of score changes with reasons (e.g. “+5 completed 5-star task”, “-10 cancelled <3h”).
    - **Performance:** Total completed tasks, acceptance rate, cancel rate, average rating, open disputes count.
    - **Reviews:** List of customer reviews for this worker.
    - **Worker-to-customer ratings:** List of ratings this worker gave to customers (to spot “problem customers”).
    - **Preferred work zones:** Map or list of zones from `worker_zones` (from PDF: neighborhoods or custom polygon).
  - **Automation (from PDF):** In system settings, rules such as: “If trust < 40 then suspend and notify admin”; “If 50 tasks with rating > 4.8 then badge ‘Featured’ and temporary commission reduction.”

---

### 1.5 Disputes and support tickets

- **Purpose:** Resolve disputes opened by low rating or “Report a problem”.
- **Data:** `disputes`, `dispute_messages`, linked `cleaning_bookings` / `event_bookings`, `booking_reviews`.
- **UI:**
  - **Table:** Dispute ID, booking #, customer, worker, reason/category, status, opened at, actions (View, Reply, Resolve).
  - **Detail view:** Customer complaint text, optional media; worker reply; message thread; booking summary; actions: Refund partial, Deduct from worker, Close dispute.

---

### 1.6 Pricing and financial settings

- **Purpose:** Configure cleaning service pricing, add-ons, travel, and billing mode (time policy).
- **Data:** `cleaning_services`, `service_pricing`, `cleaning_billing_policies`, shared `service_addons`, `travel_cost_configs`.
- **UI (from PDF):**
  - **Entry:** Main menu → “Financial settings” → “Cleaning & home assistance” → “Manage pricing”.
  - **Basic pricing:** Base hourly rate (currency), minimum hours per booking.
  - **Add-ons:** List of add-ons with price (fixed or percentage), same as existing add-on system.
  - **Revenue model:** Commission (percentage or fixed).
  - **Travel settings:** Per-km rate, minimum travel fee, **distance start point** (radio): “From worker’s current location” / “From worker’s home address” / “System chooses automatically” (e.g. <90 min → current; else → home).
  - **Time billing policy (from PDF):** In cleaning pricing/billing section:
    - Radio: “Charge full booked time” vs “Charge actual working time (recommended)”.
    - If actual: “Minimum billable minutes” (e.g. 120).
  - **Billing policies:** List/create/edit `cleaning_billing_policies` (e.g. which policy is default, which applies to which booking types).

---

### 1.7 Coverage by zone (simplified)

- **Purpose:** See which zones have worker coverage and spot understaffed areas, without map/heatmap complexity.
- **Data:** `worker_zones` (zone name, worker count per zone). Optional later: add `zone_name` or `area` to `cleaning_bookings` for demand-by-zone.
- **UI:**
  - **Section name:** “Coverage by zone” under analytics.
  - **Coverage table:** Columns: Zone name | Workers covering | Coverage (Low / OK / High from configurable thresholds, e.g. 0–1 = Low, 2–4 = OK, 5+ = High). Sortable by worker count or name.
  - **Demand summary (optional):** One card “Bookings last 30 days” total, or reuse existing “Demand by property type” from API as a small table/chart.
  - **Zone detail (optional):** Click a zone row → drawer or modal: “Zone: X”, “Workers covering: N”, “Coverage: Low/OK/High”. Omit “Avg daily requests” until bookings have a zone field.
  - **Worker profile (already in 1.4):** In admin worker detail, show “Preferred work zones” as a **list of zone names** (no map in v1).
- **Suggested implementation:** Use existing `GET` geographic-coverage API (zones with worker counts). Add coverage level in backend or frontend from thresholds. No map library or heatmap required for v1.

---

### 1.8 Roles and admin users

- **Purpose:** Manage who can do what in the admin panel (from PDF).
- **Data:** Roles and permissions (application-specific; not in Cleaning ERD).
- **UI:**
  - **Roles:** List of roles (e.g. Super Admin, Cleaning Ops Manager, Customer Support, Onboarding Specialist, Accountant) with permission templates.
  - **Admin users:** Invite/assign role to each admin user.

---

### 1.9 System alerts and proactive safety

- **Purpose:** Surface safety and operational anomalies (from PDF).
- **Data:** `system_alerts` (or equivalent), `cleaning_time_warnings`, booking status and timestamps.
- **UI:**
  - **Alerts panel** (in live dashboard): Already in 1.1; ensure these are clearly listed:
    - Delayed mutual rating (e.g. >3h after work end, no rating).
    - Frozen location (worker “on the way” but location unchanged ~20 min).
    - SOS (highest priority; prominent + sound).
    - Time exceeded without end (e.g. 15 min past scheduled end, booking still “In progress”).
  - **Actions per alert:** Call customer, Call worker, Send reminder, Resolve/Dismiss.

---

### 1.10 Time-end warnings (admin view)

- **Purpose:** Optional admin view of “time warning” events for transparency.
- **Data:** `cleaning_time_warnings` (booking_id, booking_type, customer_response, worker_response, sent_at, responded_at).
- **UI:** Table or log: Booking #, type (cleaning/event), sent at, customer response (Extend / Commit / Finish early), worker response. Useful for support when there are billing disagreements.

---

## Data ↔ UI quick reference (from ERD)


| Section           | Main tables                                                                                                                    |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Cleaning bookings | `cleaning_bookings`, `cleaning_booking_service`, `booking_addons`                                                              |
| Event bookings    | `event_bookings`, `event_booking_service`                                                                                      |
| Workers           | `workers`, `worker_zones`, `worker_availability`, `worker_trust_logs`                                                          |
| Disputes          | `disputes`, `dispute_messages`                                                                                                 |
| Pricing           | `cleaning_services`, `service_pricing`, `cleaning_billing_policies`, `service_addons`, `travel_cost_configs`                   |
| Time / safety     | `cleaning_time_warnings`, `booking_status_logs`, `booking_security_codes`, `booking_extensions`, `sos_alerts`, `system_alerts` |
| Reviews & ratings | `booking_reviews`, `worker_customer_ratings`                                                                                   |

---

## Summary

Admin dashboard has **10 sections:** live overview, cleaning bookings, event bookings, workers, disputes, pricing & financials, coverage by zone, roles, system alerts, time-warnings log. Implement as separate pages or sub-routes; use ERD field names and enums for filters and status badges.
