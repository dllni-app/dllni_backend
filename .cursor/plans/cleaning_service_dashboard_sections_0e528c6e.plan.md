---
name: Cleaning Service Dashboard Sections
overview: A UI developer–oriented specification of all dashboard sections for the cleaning service (admin, and where relevant customer/worker), derived from the ERD data model and the requirements PDF, so implementation can proceed without backend context.
todos: []
isProject: false
---

# Cleaning Service Dashboard Sections (UI Developer Spec)

This plan defines **dashboard sections** for the cleaning service so a UI developer can implement layouts, sections, and placeholders. Data sources come from [cleaning_service_erd_231c9672.plan.md](.cursor/plans/cleaning_service_erd_231c9672.plan.md); behaviour and flows from the requirements PDF (تعديل خدمة التنظيف).

---

## Dashboard audiences


| Audience              | Dashboard type               | Primary use                                                                   |
| --------------------- | ---------------------------- | ----------------------------------------------------------------------------- |
| **Admin**             | Full control panel           | Operations, pricing, workers, bookings, disputes, alerts, coverage            |
| **Customer (client)** | “My bookings” + booking flow | Book cleaning/event, view/edit/cancel, track, rate, dispute                   |
| **Worker**            | “My schedule” + task flow    | Calendar, availability, zones, accept/refuse, in-progress, earnings, disputes |


Sections below are grouped by **Admin** first (main “dashboard”), then **Customer** and **Worker** areas that act as dashboards.

---

## Part 1: Admin dashboard sections

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
    - **Trust card:** Score (e.g. 85/100), log of score changes with reasons (e.g. “+5 completed 5-star task”, “-10 cancelled &lt;3h”).
    - **Performance:** Total completed tasks, acceptance rate, cancel rate, average rating, open disputes count.
    - **Reviews:** List of customer reviews for this worker.
    - **Worker-to-customer ratings:** List of ratings this worker gave to customers (to spot “problem customers”).
    - **Preferred work zones:** Map or list of zones from `worker_zones` (from PDF: neighborhoods or custom polygon).
  - **Automation (from PDF):** In system settings, rules such as: “If trust &lt; 40 then suspend and notify admin”; “If 50 tasks with rating &gt; 4.8 then badge ‘Featured’ and temporary commission reduction.”

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
  - **Travel settings:** Per-km rate, minimum travel fee, **distance start point** (radio): “From worker’s current location” / “From worker’s home address” / “System chooses automatically” (e.g. &lt;90 min → current; else → home).
  - **Time billing policy (from PDF):** In cleaning pricing/billing section:
    - Radio: “Charge full booked time” vs “Charge actual working time (recommended)”.
    - If actual: “Minimum billable minutes” (e.g. 120).
  - **Billing policies:** List/create/edit `cleaning_billing_policies` (e.g. which policy is default, which applies to which booking types).

---

### 1.7 Geographic coverage analytics

- **Purpose:** See demand vs worker coverage and identify gaps (from PDF).
- **Data:** Aggregated bookings by zone/area (from booking address or property), `worker_zones` (which workers cover which areas).
- **UI:**
  - **Section name:** “Geographic coverage analytics” under analytics.
  - **Live coverage map (heatmap):**
    - **Layer 1 – Demand:** Heatmap of request density by area (e.g. red = high demand).
    - **Layer 2 – Workers:** Heatmap of worker preferred zones (e.g. blue = high coverage).
  - **Simulation tool:** Click a neighborhood → card: “Neighborhood: X”, “Workers covering: N”, “Avg daily requests: M”, “Coverage: Low/OK/High”.
  - **Worker profile:** In admin worker detail, show “Preferred work zones” (list/map).

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
    - Delayed mutual rating (e.g. &gt;3h after work end, no rating).
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

## Part 2: Customer-facing “dashboard” sections

These are the main sections a customer sees (e.g. “My bookings” and booking flow).

### 2.1 Home / service choice

- **Purpose:** Single clear CTA and three main actions (from PDF).
- **UI:** One main question: “What can our assistant help you with today?” + three buttons: [Home cleaning], [Event assistance], [Something else].

---

### 2.2 Cleaning booking flow (guided)

- **Purpose:** Collect property description and get estimated price (guided description → estimated sqm/hours).
- **Data (for UI):** Steps and options that map to `cleaning_bookings.property_type`, `property_details`, `estimated_sqm`, `estimated_hours`; `cleaning_services`, `service_pricing`.
- **UI:** Step 1: Welcome. Step 2: Property description – property type (Studio / Apartment / Villa / Office), bedrooms (if applicable), bathrooms, living room size (Small / Medium / Large), balcony/terrace (None / Small / Large). Step 3: Summary with estimated hours and price; optional add-ons (horizontal strip with prices; PDF: cleaning supplies with optional worker percentage). Step 4: Date/time. Step 5: **Booking summary** + checkbox: “I agree to Terms of Service and safe environment…” (required). Step 6: Confirm booking.

---

### 2.3 Event booking flow

- **Purpose:** Event type, guest count, tasks, and optional gender preference (from PDF).
- **Data:** Maps to `event_bookings` (event_type, guest_count_min/max, gender_preference, suggested_team_size, etc.).
- **UI:** Event type (Family dinner / Birthday / Large gathering / Funeral / Other) → Guest count (e.g. &lt;10, 10–25, 25–50, 50+) → Nature of help (checkboxes: hospitality, kitchen prep, dishes, setup/cleanup) → Special requirements (e.g. male/female only) → Smart result: “We suggest: N helpers, X hours, estimated cost” → [Confirm] or [Adjust]. Then date/time and same confirmation + terms as cleaning.

---

### 2.4 My bookings (customer)

- **Purpose:** List and open upcoming and past bookings.
- **Data:** `cleaning_bookings`, `event_bookings` for current user.
- **UI:** List/cards: Booking #, type (cleaning/event), date/time, status, worker name (if assigned). Actions: View details, **Edit booking** (if allowed), **Cancel booking**. From detail: Edit, Cancel, Track (if worker on the way), Security code (when worker arrived), Confirm start/end, Rate, Report problem.

---

### 2.5 Booking detail (customer)

- **Purpose:** See status, worker (with trust profile), track, confirm start/end, manage time, rate, dispute.
- **Data:** One booking + worker + status_logs + security_code + time_warnings + extensions.
- **UI:**
  - Status timeline and current step.
  - **Worker card (trust profile from PDF):** Photo, first name only, Verified ID badge, “No convictions” badge, rating, “Completed X tasks”, short bio, 2–3 reviews; option to reject worker (then re-search); optional “Request previous worker”.
  - **Pre-arrival:** 24h/3h confirmations; 1h: “Worker on the way” + map; on arrival: **Mutual security code** (4 digits) + “Confirm start” popup for customer.
  - **In progress:** Timer, **SOS** button; **Manage remaining time** (from PDF): 15 min before end – [Request extension] [Commit to current time] [Finish early].
  - **End:** Worker taps “End” → customer popup: “Did [worker] finish?” [Yes] [No / Problem] [Request extension]. Then rating; if &lt;3 stars → auto-open dispute form.
  - **Edit booking (from PDF):** Reschedule (free &gt;24h; fee &lt;24h), add hours, add services; price updates; confirm; notify worker.
  - **Cancel booking:** Confirmation text by timing (free &gt;24h; fee 3–24h; no cancel &lt;3h, suggest contact support).

---

### 2.6 Dispute / report problem (customer)

- **Purpose:** Open or complete a dispute (after low rating or from “Report problem”).
- **Data:** `disputes`, `dispute_messages`; linked booking and review.
- **UI:** Problem type (service quality / property damage / worker behavior / payment / other), description, optional media upload → Confirmation: “Ticket #XXX opened; we’ll respond within 24h.”

---

## Part 3: Worker-facing “dashboard” sections

### 3.1 My schedule (worker)

- **Purpose:** Calendar and daily list of assignments (from PDF).
- **Data:** `cleaning_bookings`, `event_bookings` where worker is assigned; `worker_availability`.
- **UI:** Calendar view (month); days with bookings highlighted (e.g. blue), blocked/leave days (e.g. grey). Tap day → list of “Booking cards” for that day (time, type, duration, earnings). FAB or button: **Manage availability**.

---

### 3.2 Manage availability (worker)

- **Purpose:** Set default hours, block days or slots, set **preferred work zones** (from PDF).
- **Data:** `worker_availability`, `worker_zones`.
- **UI:** Tabs or sections: (1) **Working hours** – default weekly slots (e.g. Mon–Thu 9–17). (2) **Leave and blocks** – tap day to block full day or specific time. (3) **Work zones** – map of city; tap neighborhoods to toggle “preferred” (highlighted); optional “Draw my zone” (polygon); list of selected zones with remove; [Save].

---

### 3.3 New booking request (worker)

- **Purpose:** Accept or refuse incoming assignment (from PDF).
- **Data:** One booking (service type, date/time, duration, add-ons, approximate area only), expected earnings.
- **UI:** Notification “New booking request”. Screen: service type, date/time, duration, add-ons, approximate area (e.g. neighborhood), **Your expected earnings: X**. Countdown (e.g. 5 min). [Accept] [Refuse].

---

### 3.4 In-progress task (worker)

- **Purpose:** Navigate, show security code, start/end work, handle extension, SOS (from PDF).
- **Data:** One booking, `booking_security_codes`, `cleaning_time_warnings`, `booking_extensions`.
- **UI:** 1h before: [I’m on my way] → map to customer. On arrival: **Show 4-digit security code**; after customer confirms: [Start work]. During: Timer, task checklist, **SOS**. **Manage remaining time** (15 min before end): [Request extension] [Commit to current time] [Finish early]. Extension request from customer → popup: “Customer requests +1h. Your extra earnings: X. [Accept] [Decline].” End: [End work] → “Waiting for customer confirmation” → “Task completed.”

---

### 3.5 Wallet / earnings (worker)

- **Purpose:** Show balance, commission, and transaction history (from PDF).
- **Data:** Wallet/balance (out of scope in ERD but in PDF); from bookings: completed task earnings.
- **UI:** Balance, commission rate, withdrawals; list of completed tasks with earnings (and “Under review” if disputed).

---

### 3.6 Dispute response (worker)

- **Purpose:** Let worker respond to a dispute (from PDF).
- **Data:** `disputes`, `dispute_messages`.
- **UI:** Notification when dispute opened; in earnings/tasks: “Under review” + [View dispute]. Screen: Customer complaint, field for worker reply, [Submit]. “Your response has been sent to support.”

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

## Summary for UI developer

- **Admin:** 10 sections (live overview, cleaning bookings, event bookings, workers, disputes, pricing & financials, geographic coverage, roles, system alerts, time-warnings log).
- **Customer:** 6 areas (home/choice, cleaning flow, event flow, my bookings, booking detail with trust profile and time/SOS flows, dispute/report).
- **Worker:** 6 areas (schedule, availability + zones, new request, in-progress task, wallet/earnings, dispute response).

Implement sections as separate pages or sub-routes; use the ERD field names and enums for filters and status badges. Keep safety elements (SOS, mutual confirmation, time-end options) and trust (worker profile, ratings, dispute flow) consistent with the PDF requirements.