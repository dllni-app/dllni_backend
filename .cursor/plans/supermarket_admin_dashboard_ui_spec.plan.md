---
name: Supermarket Admin Dashboard UI Spec
overview: UI-developer-facing specification of admin-only dashboard sections for the Supermarket module (Dllni Supermarket), derived from the ERD data model and the supermarket specification PDF. Enables layout and placeholder implementation without backend context.
todos: []
isProject: false
---

# Supermarket – Admin Dashboard Sections (UI Developer Spec)

This plan defines **admin-only** dashboard sections for the Supermarket module (grocery product-commerce, pickup-only). Data sources: [supermarket_system_erd.plan.md](.cursor/plans/supermarket_system_erd.plan.md). Behaviour and scope follow the supermarket specification PDF (سوبرماركت دللني توصيف). Customer and store-owner (merchant) panels are out of scope here.

---

## 1. Live overview / command center

- **Purpose:** High-level KPIs and operational snapshot; first screen after admin login.
- **Data (ERD):** Counts from `sm_orders` (by status), `sm_stores` (active/suspended), `sm_order_disputes` (open), optional today’s revenue; low-stock products count from `sm_products` (stock_quantity <= low_stock_threshold).
- **UI:**
  - **KPI cards:** Today’s orders (total, by status: Pending, Accepted, Preparing, ReadyForPickup, Completed), active stores, open disputes, orders pending pickup, low-stock alerts count.
  - **Quick links:** Jump to Orders, Stores, Disputes, Low stock.
- **Notes:** Low-stock and open-dispute counts should be visually prominent (e.g. warning style) when > 0.

---

## 2. Stores management

- **Purpose:** List, verify, suspend, and monitor all supermarket stores.
- **Data:** `sm_stores`, `sm_store_hours`, `sm_store_documents`, `sm_store_trust_logs`, `sm_store_daily_stats` (summary).
- **UI:**
  - **List/table:** Store name, slug, owner (user), address, phone, average_rating, total_reviews, trust_score, warning_count, is_active, is_featured, suspension_until, actions (View, Suspend/Activate, Verify documents, View trust log).
  - **Filters:** Active/Inactive/Suspended, featured, trust score range, search by name/slug.
  - **Detail view (drawer/page):**
    - Store info: name, description, address, coordinates, phone, email, hours (from `sm_store_hours`).
    - **Trust card:** trust_score, warning_count; log of trust changes from `sm_store_trust_logs` (reason, delta, created_at).
    - **Documents:** List from `sm_store_documents` (document_type: Identity, CommercialRegistration, HealthCertificate, Other; verification_status; expiry if applicable). Actions: View, Approve, Reject.
    - **Daily stats:** Last 7/30 days from `sm_store_daily_stats` (orders count, revenue if stored).
- **Notes:** Suspension and document verification are key admin actions; document types and verification workflow follow ERD and PDF.

---

## 3. Categories and products (catalog)

- **Purpose:** View and moderate catalog structure and product availability across stores; highlight low stock and expiry.
- **Data:** `sm_categories`, `sm_products`, `sm_inventory_logs` (optional for detail), shared `master_products` where linked.
- **UI:**
  - **Store selector:** Filter by store (or “All stores” for cross-store view).
  - **Categories:** List categories per store (name, slug, sort_order, product count). Actions: View products, Edit sort order (if admin can change).
  - **Products table:** Product name, store, category, barcode, price, discounted_price, stock_quantity, low_stock_threshold, expires_at, is_available, source_type (BarcodeScan, CatalogSearch, Manual, Template, BulkImport), actions (View, Toggle availability, View inventory log).
  - **Filters:** Store, category, is_available, low stock (stock_quantity <= low_stock_threshold), expiring soon, source_type.
  - **Detail view:** Full product fields, optional inventory log history, link to master_product if present.
- **Notes:** Product creation/edit may stay in store-owner panel; admin view is for oversight, moderation, and low-stock/expiry alerts.

---

## 4. Orders management

- **Purpose:** List all orders, filter by status/store/customer, view timeline and pickup state, and support dispute handling.
- **Data:** `sm_orders`, `sm_order_items`, `sm_order_status_logs`; relations: customer, store, coupon; optional `sm_order_disputes` (has_dispute flag).
- **UI:**
  - **Table columns:** Order #, customer, store, status (`SmOrderStatus`: Pending, Accepted, Preparing, ReadyForPickup, Completed, Cancelled), pickup_mode (ImmediatePickup, ScheduledPickup), pickup_scheduled_for, ready_for_pickup_at, picked_up_at, total_amount, has dispute, created_at, actions (View, Change status if needed, Open dispute).
  - **Filters:** Status, store, date range, pickup mode, has dispute.
  - **Detail view:** Full order fields (subtotal, discount_amount, service_fee, total_amount, cancellation_fee_amount, special_instructions, cancellation_reason); items list; status timeline (`sm_order_status_logs`); pickup confirmation timestamps; link to dispute if any.
- **Notes:** Pickup lifecycle (ready_for_pickup_at, picked_up_at, customer_pickup_confirmed_at) should be clearly visible for support.

---

## 5. Offers and promotions

- **Purpose:** View and moderate store-run offers and their product links.
- **Data:** `sm_offers`, `sm_offer_products`; `sm_stores`.
- **UI:**
  - **Offers table:** Store, offer name, type/label, starts_at, ends_at, is_active, linked products count, actions (View, Deactivate/Activate).
  - **Filters:** Store, active, date range.
  - **Detail view:** Offer fields; list of products in offer (`sm_offer_products`) with discounted price or rule; edit if admin is allowed to change.
- **Notes:** Offer creation may be store-owner; admin focuses on visibility and moderation (e.g. deactivate inappropriate offers).

---

## 6. Coupons management

- **Purpose:** View and manage store-issued coupons (codes, validity, usage).
- **Data:** `sm_coupons`; optional usage count from orders.
- **UI:**
  - **Table:** Code, store, type (e.g. fixed/percentage), value, starts_at, ends_at, is_active, usage count (if available), actions (View, Deactivate, Edit if allowed).
  - **Filters:** Store, active, date range.
  - **Detail view:** Full coupon config, list of orders that used it (if data available).
- **Notes:** Prefer same code/validation rules as ERD (e.g. unique code per platform or per store as per design).

---

## 7. Order disputes and support tickets

- **Purpose:** Resolve order-related disputes opened by customers or stores.
- **Data:** `sm_order_disputes`, `sm_order_dispute_messages`, linked `sm_orders`, customer and store info.
- **UI:**
  - **Table:** Ticket #, order #, customer, store, status (`SmDisputeStatus`: Open, UnderReview, Resolved, Closed), opened at, actions (View, Reply, Resolve, Close).
  - **Filters:** Status, store, date range.
  - **Detail view:** Complaint summary; message thread (`sm_order_dispute_messages`); order summary; actions: Refund partial, Deduct from store, Close dispute, change status.
- **Notes:** Align status labels and actions with ERD and PDF dispute flow.

---

## 8. Store documents and verification

- **Purpose:** Central place to review and approve/reject store documents (identity, commercial registration, health certificate).
- **Data:** `sm_store_documents` (store_id, document_type, verification_status, file ref, expiry).
- **UI:**
  - **List/table:** Store name, document type (`SmDocumentType`: Identity, CommercialRegistration, HealthCertificate, Other), verification_status, submitted at, expiry, actions (View, Approve, Reject).
  - **Filters:** Store, document type, verification status (pending/approved/rejected).
  - **Detail view:** Document preview/download; store context; Approve/Reject with optional note.
- **Notes:** Document verification can also be reached from Store detail (section 2); this section is for bulk review and filtering.

---

## 9. Trust and compliance (store reputation)

- **Purpose:** Monitor store trust score and warning count; view trust log and apply suspension or policies.
- **Data:** `sm_stores` (trust_score, warning_count, suspension_until), `sm_store_trust_logs`.
- **UI:**
  - **Stores by trust:** List or table of stores with trust_score, warning_count, last trust change; quick actions: View log, Suspend.
  - **Trust log (per store):** Entries from `sm_store_trust_logs` (reason, score change, created_at). Optional filter by store.
  - **Automation rules (if in scope):** e.g. “If trust < X then suspend and notify admin” (UI to configure thresholds; logic is backend).
- **Notes:** Trust card and log are also in Store detail (section 2); this section gives a trust-centric view across all stores.

---

## 10. Commission rules

- **Purpose:** View and configure commission rules per store (percentage or fixed).
- **Data:** `sm_commission_rules` (store_id, type, value, is_active, is_default).
- **UI:**
  - **List:** Store, rule type (Percentage / Fixed), value, is_default, is_active, actions (View, Edit, Set default, Deactivate).
  - **Detail/form:** Store selector, commission type (`SmCommissionType`: Percentage, Fixed), value, is_default, active. Validation: one default per store if applicable.
- **Notes:** Commission applies to orders; display where used (e.g. in order or store analytics) if data exists.

---

## 11. Analytics and daily stats

- **Purpose:** View store performance over time (orders, revenue if stored) without heatmap/coverage (excluded in ERD).
- **Data:** `sm_store_daily_stats` (store_id, date, and any metrics stored: order count, revenue, etc.).
- **UI:**
  - **Section name:** e.g. “Store analytics” or “Daily stats.”
  - **Store selector and date range.**
  - **Charts/tables:** Daily (or weekly) order count, revenue if available; compare stores or single store over time.
  - **Export:** Optional CSV/Excel for date range.
- **Notes:** Exact metrics depend on what is written into `sm_store_daily_stats`; UI should be flexible to show whatever fields exist.

---

## 12. Smart assistant and recipe usage (analytics)

- **Purpose:** Monitor how customers use the smart assistant and recipe-based queries (product discovery).
- **Data:** `sm_assistant_queries` (user_id, store_id, input_mode Text/Voice, matched_recipe_id, created_at); shared `recipes` for names.
- **UI:**
  - **Summary cards:** Total queries today/week; by input mode (text vs voice); by store.
  - **Table or list:** User (or anonymized), store, input preview, input_mode, matched_recipe (if any), created_at. Filters: store, date range, has_recipe_match.
  - **Notes:** Read-only analytics; no PII beyond what admin is allowed to see.
- **Notes:** Recommendation ranking is backend; ERD stores query and durable state for analytics.

---

## 13. Recurring orders (monitoring)

- **Purpose:** View and monitor recurring orders for support and fraud/compliance.
- **Data:** `sm_recurring_orders`, `sm_recurring_order_items`; status `SmRecurringOrderStatus`: Active, Paused, Cancelled.
- **UI:**
  - **Table:** Customer, store, status, frequency/next_run_at, items count, created_at, actions (View).
  - **Filters:** Status, store, date range.
  - **Detail view:** Recurring order config, list of items (from `sm_recurring_order_items`), next run, history of generated orders if available.
- **Notes:** Admin may only view and pause/cancel in exceptional cases; creation/edit is customer-facing.

---

## 14. Roles and admin users

- **Purpose:** Manage who can access the admin panel and with which permissions (same concept as cleaning admin).
- **Data:** Application-level roles and permissions (e.g. from shared `roles`, `permissions`, `permission_role`); not in Supermarket ERD.
- **UI:**
  - **Roles:** List of admin roles with permission templates (e.g. Super Admin, Operations, Support, Finance). Add/Edit role and assign permissions.
  - **Admin users:** List of admin users; invite new admin; assign role per user.
- **Notes:** Naming and permission set can align with supermarket-specific needs (e.g. Store approver, Dispute resolver, Read-only analytics).

---

## Data ↔ UI quick reference (admin only)


| Section               | Main ERD tables                                                                                    |
| --------------------- | -------------------------------------------------------------------------------------------------- |
| Live overview         | `sm_orders`, `sm_stores`, `sm_order_disputes`, `sm_products` (low stock)                           |
| Stores                | `sm_stores`, `sm_store_hours`, `sm_store_documents`, `sm_store_trust_logs`, `sm_store_daily_stats` |
| Categories & products | `sm_categories`, `sm_products`, `sm_inventory_logs`, `master_products`                             |
| Orders                | `sm_orders`, `sm_order_items`, `sm_order_status_logs`                                              |
| Offers                | `sm_offers`, `sm_offer_products`                                                                   |
| Coupons               | `sm_coupons`                                                                                       |
| Disputes              | `sm_order_disputes`, `sm_order_dispute_messages`                                                   |
| Store documents       | `sm_store_documents`                                                                               |
| Trust & compliance    | `sm_stores`, `sm_store_trust_logs`                                                                 |
| Commission            | `sm_commission_rules`                                                                              |
| Analytics             | `sm_store_daily_stats`                                                                             |
| Smart assistant       | `sm_assistant_queries`, `recipes`                                                                  |
| Recurring orders      | `sm_recurring_orders`, `sm_recurring_order_items`                                                  |
| Roles                 | App-level `roles`, `permissions`                                                                   |


---

## Summary for UI developer

- **Scope:** Admin dashboard only — 14 sections.
- **Sections:** (1) Live overview, (2) Stores, (3) Categories & products, (4) Orders, (5) Offers & promotions, (6) Coupons, (7) Order disputes, (8) Store documents & verification, (9) Trust & compliance, (10) Commission rules, (11) Analytics / daily stats, (12) Smart assistant usage, (13) Recurring orders monitoring, (14) Roles and admin users.
- **Implementation:** Each section as a separate page or sub-route; use ERD field and enum names for filters and status badges. No delivery/heatmap UI (excluded in ERD). Pickup-only order lifecycle and store trust/document flows are central. If the PDF specifies extra screens or copy (e.g. Arabic labels, specific workflows), integrate those into these sections.

