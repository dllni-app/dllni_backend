---
name: Restaurant Admin Dashboard UI Spec
overview: UI-developer-facing specification of admin-only dashboard sections for the Restaurant module (Dllni Restaurants), derived from the ERD data model and the restaurant specification PDF. Pickup and dine-in only; no delivery. Enables layout and placeholder implementation without backend context.
todos: []
isProject: false
---

# Restaurant – Admin Dashboard Sections (UI Developer Spec)

This plan defines **admin-only** dashboard sections for the Restaurant module (browse, menu, cart, checkout for **pickup and dine-in only**). Data sources: [restaurant_system_erd_c43e2031.plan.md](.cursor/plans/restaurant_system_erd_c43e2031.plan.md). Behaviour and scope follow the restaurant specification PDF (توصيف المطاعم دللني). Customer and restaurant-owner (merchant) panels are out of scope here.

---

## 1. Live overview / command center

- **Purpose:** High-level KPIs and operational snapshot; first screen after admin login.
- **Data (ERD):** Counts from `orders` (by status), `restaurants` (active/suspended), `restaurant_order_disputes` (open); optional today’s revenue; low-stock products count from `products` (stock_quantity <= low_stock_threshold).
- **UI:**
  - **KPI cards:** Today’s orders (total, by status), active restaurants, open disputes, orders pending pickup / ready for pickup, low-stock alerts count.
  - **Quick links:** Jump to Orders, Restaurants, Disputes, Low stock.
- **Notes:** Low-stock and open-dispute counts should be visually prominent (e.g. warning style) when > 0. Order status enum: Pending, Accepted, Preparing, ReadyForPickup, Completed, Cancelled (from ERD).

---

## 2. Restaurants management

- **Purpose:** List, verify, suspend, and monitor all restaurants; control visibility and reputation.
- **Data:** `restaurants`, `operating_hours`, `restaurant_documents`, `restaurant_reputation_logs`, `restaurant_penalties`, `restaurant_daily_stats` / `restaurant_monthly_stats` (summary); `cuisine_types` via `cuisine_type_restaurant`.
- **UI:**
  - **List/table:** Name, slug, owner (user), address, phone, average_rating, total_reviews, reputation_score, warning_count, visibility_score, manual_visibility_override, price_range, is_active, is_featured, suspension_until, actions (View, Suspend/Activate, Verify documents, View reputation, Override visibility).
  - **Filters:** Active/Inactive/Suspended, featured, cuisine type, price range, reputation range, search by name/slug.
  - **Detail view (drawer/page):**
    - Restaurant info: name, description, address, coordinates, phone, email, estimated_preparation_time, minimum_order_amount, price_range, operating_hours, cuisine types.
    - **Documents:** List from `restaurant_documents` (document_type: Identity, CommercialRegistration, HealthCertificate, Other; verification_status). Actions: View, Approve, Reject.
    - **Reputation card:** reputation_score, warning_count; log from `restaurant_reputation_logs` (reason, delta, created_at).
    - **Penalties:** List from `restaurant_penalties` (type, amount/effect, reason, created_at).
    - **Daily/monthly stats:** Last 7/30 days from `restaurant_daily_stats`, `restaurant_monthly_stats` (orders, revenue if stored).
- **Notes:** Visibility and reputation are restaurant-specific; manual_visibility_override allows admin to force show/hide regardless of score.

---

## 3. Categories and products (menu catalog)

- **Purpose:** View and moderate menu structure, product availability, modifiers, and out-of-stock substitutions across restaurants.
- **Data:** `categories`, `products`, `modifier_groups`, `modifiers`, `modifier_group_product`, `restaurant_product_substitutions`, `inventory_logs` (optional for detail); shared `master_products` where linked.
- **UI:**
  - **Restaurant selector:** Filter by restaurant (or “All restaurants” for cross-store view).
  - **Categories:** List per restaurant (name, slug, sort_order, product count). Actions: View products.
  - **Products table:** Product name, restaurant, category, price, discounted_price, stock_quantity, low_stock_threshold, preparation_time, is_available, is_featured, master_product link, actions (View, Toggle availability, View modifiers, View substitutions, View inventory log).
  - **Filters:** Restaurant, category, is_available, low stock, is_featured, search.
  - **Detail view:** Full product fields; modifier groups and modifiers linked via `modifier_group_product`; substitutions from `restaurant_product_substitutions`; optional inventory log history.
- **Notes:** Product creation/edit may stay in restaurant-owner panel; admin view is for oversight, moderation, and low-stock/expiry alerts. Substitutions support out-of-stock replacement flow.

---

## 4. Orders management

- **Purpose:** List all orders (pickup and dine-in), filter by status/restaurant/customer, view timeline and pickup/dine-in state, support dispute handling.
- **Data:** `orders`, `order_items`, `order_item_modifier`, `order_status_logs`; relations: user (customer), restaurant, promo_code, assigned_staff; optional `restaurant_order_disputes` (has_dispute flag).
- **UI:**
  - **Table columns:** Order #, customer, restaurant, status (`OrderStatus`), order_type (Pickup / DineIn), pickup_mode (ImmediatePickup, ScheduledPickup), pickup_scheduled_for, ready_for_pickup_at, picked_up_at, customer_pickup_confirmed_at, total_amount, has dispute, created_at, actions (View, Change status if needed, Open dispute).
  - **Filters:** Status, restaurant, order_type, date range, pickup mode, has dispute.
  - **Detail view:** Full order fields (subtotal, discount_amount, tax_amount, service_fee, total_amount, cancellation_fee_amount, special_instructions, cancellation_reason); order items with modifiers; status timeline (`order_status_logs`); pickup/dine-in timestamps (accepted_at, preparing_at, completed_at); assigned_staff; link to dispute if any.
- **Notes:** Pickup lifecycle (ready_for_pickup_at, picked_up_at, customer_pickup_confirmed_at) and dine-in flow should be clearly visible. No delivery in scope (ERD excluded).

---

## 5. Offers and promotions

- **Purpose:** View and moderate restaurant-run offers and their product links.
- **Data:** `offers`, `offer_product`; `restaurants`.
- **UI:**
  - **Offers table:** Restaurant, offer name, type/label, starts_at, ends_at, is_active, linked products count, actions (View, Deactivate/Activate).
  - **Filters:** Restaurant, active, date range.
  - **Detail view:** Offer fields; list of products in offer (`offer_product`) with discount; edit if admin is allowed.
- **Notes:** Offer creation may be restaurant-owner; admin focuses on visibility and moderation.

---

## 6. Promo codes management

- **Purpose:** View and manage restaurant-issued promo codes (codes, validity, usage).
- **Data:** `promo_codes`; optional usage count from orders.
- **UI:**
  - **Table:** Code, restaurant, discount_type, value, starts_at, ends_at, is_active, usage count (if available), actions (View, Deactivate, Edit if allowed).
  - **Filters:** Restaurant, active, date range.
  - **Detail view:** Full promo config, list of orders that used it (if data available).
- **Notes:** DiscountType and validation rules as per ERD and PDF.

---

## 7. Order disputes and support tickets

- **Purpose:** Resolve order-related disputes opened by customers or restaurants.
- **Data:** `restaurant_order_disputes`, `restaurant_order_dispute_messages`, linked `orders`, customer and restaurant info.
- **UI:**
  - **Table:** Ticket #, order #, customer, restaurant, status (`RestaurantDisputeStatus`: Open, UnderReview, Resolved, Closed), opened at, actions (View, Reply, Resolve, Close).
  - **Filters:** Status, restaurant, date range.
  - **Detail view:** Complaint summary; message thread (`restaurant_order_dispute_messages`); order summary; actions: Refund partial, Deduct from restaurant, Close dispute, change status.
- **Notes:** Align status labels and actions with ERD and PDF dispute flow.

---

## 8. Restaurant documents and verification

- **Purpose:** Central place to review and approve/reject restaurant documents (identity, commercial registration, health certificate).
- **Data:** `restaurant_documents` (restaurant_id, document_type, verification_status, file ref).
- **UI:**
  - **List/table:** Restaurant name, document type (`RestaurantDocumentType`: Identity, CommercialRegistration, HealthCertificate, Other), verification_status, submitted at, actions (View, Approve, Reject).
  - **Filters:** Restaurant, document type, verification status.
  - **Detail view:** Document preview/download; restaurant context; Approve/Reject with optional note.
- **Notes:** Document verification can also be reached from Restaurant detail (section 2); this section is for bulk review and filtering.

---

## 9. Reputation, penalties and compliance

- **Purpose:** Monitor restaurant reputation score and warning count; view reputation log and penalties; apply suspension or visibility override.
- **Data:** `restaurants` (reputation_score, warning_count, visibility_score, manual_visibility_override, suspension_until), `restaurant_reputation_logs`, `restaurant_penalties`.
- **UI:**
  - **Restaurants by reputation:** List or table with reputation_score, warning_count, visibility_score, last reputation change; quick actions: View log, Apply penalty, Suspend, Override visibility.
  - **Reputation log (per restaurant):** Entries from `restaurant_reputation_logs` (reason, score change, created_at). Optional filter by restaurant.
  - **Penalties:** List from `restaurant_penalties` (restaurant, type `PenaltyType`, reason, created_at). Optional: create penalty from admin (if in scope).
- **Notes:** Reputation and visibility are key for ranking and discovery; penalties support governance (ERD).

---

## 10. Restaurant staff and roles (per-restaurant)

- **Purpose:** View restaurant-level staff and role assignments; moderate access (if admin can assign/revoke).
- **Data:** `restaurant_staff`, `restaurant_roles`, `restaurant_role_permission`; shared `permissions`; `users`.
- **UI:**
  - **Restaurant selector** then **Staff table:** User name, email, role(s) at this restaurant, joined at, actions (View, Change role, Revoke if allowed).
  - **Roles (per restaurant):** List `restaurant_roles` with permission set; Add/Edit role and assign permissions from shared `permissions` (PermissionGroup if applicable).
  - **Detail view (staff member):** User info, list of roles at this restaurant, permissions summary.
- **Notes:** Role-based access is per restaurant (restaurant_roles, restaurant_staff); platform admin roles are in section 14.

---

## 11. Analytics and stats (daily / monthly)

- **Purpose:** View restaurant performance over time (orders, revenue if stored) without heatmap (excluded in ERD).
- **Data:** `restaurant_daily_stats`, `restaurant_monthly_stats` (restaurant_id, date/month, and stored metrics).
- **UI:**
  - **Section name:** e.g. “Restaurant analytics” or “Daily / monthly stats.”
  - **Restaurant selector and date range.**
  - **Charts/tables:** Daily or monthly order count, revenue if available; compare restaurants or single restaurant over time.
  - **Export:** Optional CSV/Excel for date range.
- **Notes:** Exact metrics depend on what is written into daily/monthly stats; UI should be flexible to show whatever fields exist.

---

## 12. Smart assistant usage (analytics)

- **Purpose:** Monitor how customers use the smart assistant and recipe-based queries in restaurant context.
- **Data:** `restaurant_assistant_queries` (user_id, restaurant_id, input_mode Text/Voice, matched_recipe_id, created_at); shared `recipes` for names.
- **UI:**
  - **Summary cards:** Total queries today/week; by input mode (text vs voice); by restaurant.
  - **Table or list:** User (or anonymized), restaurant, input preview, input_mode, matched_recipe (if any), created_at. Filters: restaurant, date range, has_recipe_match.
- **Notes:** Read-only analytics; no PII beyond what admin is allowed to see. Recommendation ranking is backend; ERD stores query and durable state.

---

## 13. Recurring orders (monitoring)

- **Purpose:** View and monitor recurring orders for support and compliance.
- **Data:** `restaurant_recurring_orders`, `restaurant_recurring_order_items`; status `RecurringOrderStatus`: Active, Paused, Cancelled.
- **UI:**
  - **Table:** Customer, restaurant, status, frequency/next_run_at, items count, created_at, actions (View).
  - **Filters:** Status, restaurant, date range.
  - **Detail view:** Recurring order config, list of items (`restaurant_recurring_order_items`), next run, history of generated orders if available.
- **Notes:** Admin may only view and pause/cancel in exceptional cases; creation/edit is customer-facing.

---

## 14. Reviews moderation (optional)

- **Purpose:** View and moderate customer reviews for restaurants; flag or hide inappropriate content.
- **Data:** `reviews` (user_id, order_id, restaurant context); `restaurants`.
- **UI:**
  - **Table:** Restaurant, customer (or anonymized), order #, rating, review text snippet, created_at, actions (View, Hide/Flag, Respond if allowed).
  - **Filters:** Restaurant, rating range, date range.
  - **Detail view:** Full review text, order context, restaurant reply if any; Hide/Flag/Approve.
- **Notes:** One review per user per order (unique on user_id + order_id per ERD). Optional section if moderation is in scope.

---

## 15. Roles and admin users (platform admin)

- **Purpose:** Manage who can access the admin panel and with which permissions (platform-level, not per-restaurant).
- **Data:** Application-level roles and permissions (e.g. shared `roles`, `permissions`, `permission_role`); not in Restaurant ERD.
- **UI:**
  - **Roles:** List of admin roles with permission templates (e.g. Super Admin, Restaurant Ops, Support, Finance). Add/Edit role and assign permissions.
  - **Admin users:** List of admin users; invite new admin; assign role per user.
- **Notes:** Naming and permission set can align with restaurant-specific needs (e.g. Restaurant approver, Dispute resolver, Read-only analytics).

---

## Data ↔ UI quick reference (admin only)


| Section                | Main ERD tables                                                                                                                                                                       |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Live overview          | `orders`, `restaurants`, `restaurant_order_disputes`, `products` (low stock)                                                                                                          |
| Restaurants            | `restaurants`, `operating_hours`, `restaurant_documents`, `restaurant_reputation_logs`, `restaurant_penalties`, `restaurant_daily_stats`, `restaurant_monthly_stats`, `cuisine_types` |
| Categories & products  | `categories`, `products`, `modifier_groups`, `modifiers`, `modifier_group_product`, `restaurant_product_substitutions`, `inventory_logs`                                              |
| Orders                 | `orders`, `order_items`, `order_item_modifier`, `order_status_logs`                                                                                                                   |
| Offers                 | `offers`, `offer_product`                                                                                                                                                             |
| Promo codes            | `promo_codes`                                                                                                                                                                         |
| Disputes               | `restaurant_order_disputes`, `restaurant_order_dispute_messages`                                                                                                                      |
| Documents              | `restaurant_documents`                                                                                                                                                                |
| Reputation & penalties | `restaurants`, `restaurant_reputation_logs`, `restaurant_penalties`                                                                                                                   |
| Staff & roles          | `restaurant_staff`, `restaurant_roles`, `restaurant_role_permission`, `permissions`                                                                                                   |
| Analytics              | `restaurant_daily_stats`, `restaurant_monthly_stats`                                                                                                                                  |
| Smart assistant        | `restaurant_assistant_queries`, `recipes`                                                                                                                                             |
| Recurring orders       | `restaurant_recurring_orders`, `restaurant_recurring_order_items`                                                                                                                     |
| Reviews                | `reviews`                                                                                                                                                                             |
| Platform admin         | App-level `roles`, `permissions`                                                                                                                                                      |


---

## Summary for UI developer

- **Scope:** Admin dashboard only — 15 sections.
- **Sections:** (1) Live overview, (2) Restaurants, (3) Categories & products, (4) Orders, (5) Offers & promotions, (6) Promo codes, (7) Order disputes, (8) Restaurant documents & verification, (9) Reputation & penalties, (10) Restaurant staff & roles, (11) Analytics (daily/monthly), (12) Smart assistant usage, (13) Recurring orders monitoring, (14) Reviews moderation (optional), (15) Roles and admin users.
- **Implementation:** Each section as a separate page or sub-route; use ERD field and enum names for filters and status badges. **Pickup and dine-in only** — no delivery UI. Order types: `Pickup`, `DineIn`. Restaurant-specific concepts: reputation_score, visibility_score, manual_visibility_override, penalties, per-restaurant staff/roles. If the PDF specifies extra screens or copy (e.g. Arabic labels, specific workflows), integrate those into these sections.

