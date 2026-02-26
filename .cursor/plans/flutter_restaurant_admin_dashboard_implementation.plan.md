---
name: ""
overview: ""
todos: []
isProject: false
---

# Flutter – Restaurant Admin Dashboard Implementation Plan

**Audience:** Flutter developer  
**UI spec:** [restaurant_admin_dashboard_ui_spec.plan.md](.cursor/plans/restaurant_admin_dashboard_ui_spec.plan.md)  
**API contract:** [docs/API_CONTRACT_RESTAURANTS.md](../docs/API_CONTRACT_RESTAURANTS.md)  
**Client behavior:** [docs/API_CONTRACT_CLIENT_BEHAVIOR.md](../docs/API_CONTRACT_CLIENT_BEHAVIOR.md)

---

## 1. Best practices – UI implementation

- **Layout:** Responsive layout: single column on mobile, cards + data table on larger screens. Use `LayoutBuilder` or a responsive wrapper so KPI cards and tables reflow. Admin dashboard is **pickup and dine-in only** (no delivery).
- **Loading / empty / error:** Every screen: (1) loading (shimmer/skeleton), (2) empty (illustration + CTA where relevant), (3) error (message + retry). Never show raw IDs or enum values; use **select menus** and labels only (per client behavior).
- **Status badges:** Use `OrderStatus` (e.g. Pending, Accepted, Preparing, ReadyForPickup, Completed, Cancelled) and dispute/verification enums; show localized labels and consistent colors (e.g. warning=pending, success=completed, error=cancelled).
- **Alerts:** Low-stock and open-dispute counts must be visually prominent (e.g. warning style) when > 0.
- **Accessibility:** Contrast, touch targets ≥ 44pt, semantic labels; support larger text.
- **RTL:** Support RTL for Arabic where layout is direction-dependent.

---

## 2. Data handling

- **Auth:** Store token; send `Authorization: Bearer {token}`. Base URL: `https://dllni.mustafafares.com`, prefix: `/api/v1/`.
- **Pagination:** Use `data`, `links`, `meta`; `perPage` (1–100), `page`. Preserve scroll on refresh when possible.
- **Filters & sort:** `filter[fieldName]=value`, `sort=field` / `sort=-field` (camelCase). Filter values (status, restaurantId, etc.) from **select menus**; store selected id/enum in page state; do not show raw value to user.
- **POST:** Backend-known data (e.g. restaurantId from context) in page state only; one dedicated input per user field.
- **PUT:** Optimistic update → send request → update UI; on success keep, on failure revert and show error.
- **Errors:** Parse 4xx/5xx and `errors` by field; generic message + retry for network/server errors.
- **Caching:** Optional short cache for overview; pull-to-refresh; invalidate lists on create/update/delete.

---

## 3. Page-by-page data & flow guide

### 3.1 Live overview / command center


| Item             | Guidance                                                                                                                                                                                                                                                                                                |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | First screen after admin login; KPIs and quick links.                                                                                                                                                                                                                                                   |
| **Data to load** | Dashboard overview: today orders (total + by status), active restaurants, open disputes, orders pending/ready for pickup, low-stock count. If API is restaurant-scoped, use restaurant selector (store selected restaurantId in state) or use first restaurant / admin aggregate endpoint if available. |
| **API**          | `GET /api/v1/restaurant/dashboard/overview?restaurantId={id}` (required). If admin has no single restaurant, either list restaurants and pick one for overview, or use an admin-level overview endpoint if documented.                                                                                  |
| **Flow**         | Load restaurants list if needed → select default (e.g. first) or use stored selection → load overview. Loading → success → render KPI cards; error → retry. Pull-to-refresh. Quick links: Orders, Restaurants, Disputes, Low stock.                                                                     |
| **UI**           | KPI cards: today orders, by status, active restaurants, open disputes, pending pickup, ready for pickup, low-stock count (highlight if > 0). Quick links to Orders, Restaurants, Disputes, Low stock.                                                                                                   |


---

### 3.2 Restaurants management


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List, verify, suspend, monitor restaurants; documents and reputation.                                                                                                                                                                                                                                                                                                                                                                     |
| **Data to load** | Paginated restaurants; filters. Detail: restaurant + operating hours, documents, reputation log, penalties, stats.                                                                                                                                                                                                                                                                                                                        |
| **API**          | List: `GET /api/v1/restaurants?perPage=20&page=1` + `filter[isActive]`, `filter[isFeatured]`, `filter[isSuspended]`, `filter[cuisineType]`, `filter[priceRange]`, `filter[reputationScoreMin/Max]`, `filter[search]`. Show: `GET /api/v1/restaurants/{id}` (with relations). Operating hours: `GET/PUT /api/v1/restaurants/{id}/operating-hours`.                                                                                         |
| **Flow**         | Filters via select menus (active, featured, cuisine, price range, etc.); store values in state. Row tap → detail (id from route; not shown to user). Documents: list in detail; Approve/Reject via `PUT /api/v1/restaurant-documents/{id}` (verification_status). Suspend/Activate: update restaurant (optimistic).                                                                                                                       |
| **UI**           | Table: name, slug, owner, address, phone, average_rating, total_reviews, reputation_score, warning_count, visibility_score, manual_visibility_override, price_range, is_active, is_featured, suspension_until, actions (View, Suspend/Activate, Verify documents, View reputation, Override visibility). Detail: info, documents (type, verification_status, View/Approve/Reject), reputation card + log, penalties, daily/monthly stats. |


---

### 3.3 Categories and products (menu catalog)


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                                                    |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View/moderate menu structure, availability, modifiers, substitutions.                                                                                                                                                                                                                                                                                                                                       |
| **Data to load** | Restaurant selector; categories per restaurant; products with filters (restaurant, category, isAvailable, lowStock, isFeatured, search).                                                                                                                                                                                                                                                                    |
| **API**          | Categories: `GET /api/v1/categories?filter[restaurantId]=` (restaurant from selector). Products: `GET /api/v1/products?perPage=20&page=1` + `filter[restaurantId]`, `filter[categoryId]`, `filter[isAvailable]`, `filter[lowStock]`, `filter[isFeatured]`, `filter[search]`. Product detail: `GET /api/v1/products/{id}` (modifierGroups, substitutions). Toggle availability: `PUT /api/v1/products/{id}`. |
| **Flow**         | Restaurant from dropdown (store id in state). Category filter from select (categories loaded by restaurantId). Product list and detail; modifiers and substitutions in detail. Optimistic update for availability toggle.                                                                                                                                                                                   |
| **UI**           | Restaurant selector; categories list (name, slug, sort_order, product count). Products table: name, restaurant, category, price, discounted_price, stock_quantity, low_stock_threshold, preparation_time, is_available, is_featured, actions (View, Toggle availability, Modifiers, Substitutions, Inventory log). Detail: full product + modifier groups + substitutions.                                  |


---

### 3.4 Orders management


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List orders (pickup/dine-in), filter, view timeline and pickup state, support disputes.                                                                                                                                                                                                                                                                                                                                                                                 |
| **Data to load** | Paginated orders; detail with items, status logs, pickup/dine-in timestamps.                                                                                                                                                                                                                                                                                                                                                                                            |
| **API**          | List: `GET /api/v1/orders?perPage=20&page=1` + `filter[status]`, `filter[restaurantId]`, `filter[orderType]`, `filter[pickupMode]`, `filter[dateFrom]`, `filter[dateTo]`, `filter[hasDispute]`, `filter[late]`. Detail: `GET /api/v1/orders/{id}`. Accept: `POST /api/v1/orders/{id}/accept` (preparationTimeMinutes, assignedEmployeeId, kitchenNotes). Reject: `POST /api/v1/orders/{id}/reject` (reason, customerMessage). Update status: `PUT /api/v1/orders/{id}`. |
| **Flow**         | Filters (status, restaurant, order type, date range, has dispute) from select/date pickers; store in state. Detail: order id from route. Accept/Reject: dedicated inputs for user data; backend-known ids in state. Optimistic update for status changes.                                                                                                                                                                                                               |
| **UI**           | Table: Order #, customer, restaurant, status, order_type (Pickup/DineIn), pickup_mode, pickup_scheduled_for, ready_for_pickup_at, picked_up_at, total_amount, has dispute, created_at, actions (View, Change status, Open dispute). Detail: full order fields, items + modifiers, status timeline, pickup/dine-in timestamps, link to dispute.                                                                                                                          |


---

### 3.5 Offers and promotions


| Item             | Guidance                                                                                                                                                                                                |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View and moderate offers; product links.                                                                                                                                                                |
| **Data to load** | Paginated offers; detail with products.                                                                                                                                                                 |
| **API**          | List: `GET /api/v1/offers` + `filter[restaurantId]`, `filter[isActive]`, `filter[startsAtFrom]`, `filter[endsAtTo]`. Detail: `GET /api/v1/offers/{id}`. Activate/Deactivate: `PUT /api/v1/offers/{id}`. |
| **Flow**         | Restaurant and active/date filters from select; open detail for product list. Optimistic update for activate/deactivate.                                                                                |
| **UI**           | Table: restaurant, offer name, type, starts_at, ends_at, is_active, linked products count, actions (View, Deactivate/Activate). Detail: offer fields + products in offer.                               |


---

### 3.6 Promo codes management


| Item             | Guidance                                                                                                                                                                                                             |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View and manage promo codes (validity, usage).                                                                                                                                                                       |
| **Data to load** | Paginated promo codes; detail; optional usage from orders.                                                                                                                                                           |
| **API**          | List: `GET /api/v1/promo-codes` + `filter[restaurantId]`, `filter[isActive]`, `filter[startsAtFrom]`, `filter[endsAtTo]`. Detail: `GET /api/v1/promo-codes/{id}`. Update/Deactivate: `PUT /api/v1/promo-codes/{id}`. |
| **Flow**         | Filters from select/date; store in state. Optimistic update for edits.                                                                                                                                               |
| **UI**           | Table: code, restaurant, discount_type, value, starts_at, ends_at, is_active, usage count, actions (View, Deactivate, Edit). Detail: full config, orders that used it if available.                                  |


---

### 3.7 Order disputes and support tickets


| Item             | Guidance                                                                                                                                                                                                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Resolve order-related disputes; message thread and actions.                                                                                                                                                                                                               |
| **Data to load** | Paginated disputes; detail with messages and order summary.                                                                                                                                                                                                               |
| **API**          | List: `GET /api/v1/restaurant-order-disputes` + `filter[status]`, `filter[restaurantId]`, `filter[dateFrom]`, `filter[dateTo]`. Detail: `GET /api/v1/restaurant-order-disputes/{id}`. Update status / reply: per contract (POST messages, PUT dispute).                   |
| **Flow**         | Filters from select; detail loads messages and order. Reply: user input → POST message; Resolve/Close: confirm → PUT. Optimistic update.                                                                                                                                  |
| **UI**           | Table: ticket #, order #, customer, restaurant, status (Open, UnderReview, Resolved, Closed), opened at, actions (View, Reply, Resolve, Close). Detail: complaint, message thread, order summary, actions (Refund partial, Deduct from restaurant, Close, change status). |


---

### 3.8 Restaurant documents and verification


| Item             | Guidance                                                                                                                                                                                                                                                                |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Bulk review and approve/reject documents.                                                                                                                                                                                                                               |
| **Data to load** | Paginated documents; filters.                                                                                                                                                                                                                                           |
| **API**          | List: `GET /api/v1/restaurant-documents` + `filter[restaurantId]`, `filter[documentType]`, `filter[verificationStatus]`. Detail: `GET /api/v1/restaurant-documents/{id}`. Approve/Reject: `PUT /api/v1/restaurant-documents/{id}` (verification_status, optional note). |
| **Flow**         | Filters (restaurant, document type, verification status) from select; store in state. Approve/Reject: optional note input; backend document id in state. Optimistic update.                                                                                             |
| **UI**           | Table: restaurant name, document type (Identity, CommercialRegistration, HealthCertificate, Other), verification_status, submitted at, actions (View, Approve, Reject). Detail: preview/download, restaurant context, Approve/Reject with note.                         |


---

### 3.9 Reputation, penalties and compliance


| Item             | Guidance                                                                                                                                                                                                 |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Monitor reputation, view log and penalties; suspension and visibility override.                                                                                                                          |
| **Data to load** | Restaurants with reputation/warning; reputation log per restaurant; penalties list.                                                                                                                      |
| **API**          | Use restaurants list (reputation_score, warning_count, visibility_score) and detail (reputationLogs, penalties). Override/Suspend: `PUT /api/v1/restaurants/{id}`.                                       |
| **Flow**         | List sorted/filtered by reputation; open restaurant for log and penalties. Apply penalty/suspend/override from detail; optimistic update.                                                                |
| **UI**           | Restaurants by reputation (score, warning_count, visibility_score, last change); actions: View log, Apply penalty, Suspend, Override visibility. Per-restaurant: reputation log entries; penalties list. |


---

### 3.10 Restaurant staff and roles (per-restaurant)


| Item             | Guidance                                                                                                                                                                             |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | View staff and role assignments per restaurant; moderate access.                                                                                                                     |
| **Data to load** | Restaurant selector; staff list for restaurant; roles and permissions.                                                                                                               |
| **API**          | Use restaurant-staff and restaurant-roles endpoints per contract (exact paths in API doc). Roles: list and assign permissions; staff: list and assign role (store role id in state). |
| **Flow**         | Restaurant from dropdown; load staff and roles. Change role: select new role from dropdown (id in state); POST/PUT.                                                                  |
| **UI**           | Restaurant selector; staff table (user, role(s), joined at, actions); roles list with permission set; staff detail (user info, roles, permissions).                                  |


---

### 3.11 Analytics and stats (daily / monthly)


| Item             | Guidance                                                                                                                                                                           |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Restaurant performance over time (orders, revenue).                                                                                                                                |
| **Data to load** | Restaurant selector + date range; daily or monthly stats.                                                                                                                          |
| **API**          | Use `restaurant_daily_stats`, `restaurant_monthly_stats` endpoints if documented; otherwise infer from contract (e.g. stats in restaurant detail or dedicated analytics endpoint). |
| **Flow**         | Restaurant and date range from selectors; load stats; optional export CSV/Excel.                                                                                                   |
| **UI**           | Restaurant selector; date range; charts/tables (order count, revenue); export button.                                                                                              |


---

### 3.12 Smart assistant usage (analytics)


| Item             | Guidance                                                                                                            |
| ---------------- | ------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Monitor assistant and recipe-based queries (read-only).                                                             |
| **Data to load** | Summary cards (queries today/week, by input mode, by restaurant); table of queries.                                 |
| **API**          | Use `restaurant_assistant_queries` (or equivalent) per contract; filters: restaurant, date range, has_recipe_match. |
| **Flow**         | Load summary and list; filters from select. No PII beyond what admin is allowed.                                    |
| **UI**           | Summary cards; table: user (or anonymized), restaurant, input preview, input_mode, matched_recipe, created_at.      |


---

### 3.13 Recurring orders (monitoring)


| Item             | Guidance                                                                                                                                                           |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | View and optionally pause/cancel recurring orders.                                                                                                                 |
| **Data to load** | Paginated recurring orders; detail with items and next run.                                                                                                        |
| **API**          | Recurring orders list/detail per contract; filters: status, restaurant, date range.                                                                                |
| **Flow**         | List with filters; detail for config and items; pause/cancel if allowed (optimistic update).                                                                       |
| **UI**           | Table: customer, restaurant, status, frequency/next_run_at, items count, created_at, actions (View). Detail: config, items, next run, history of generated orders. |


---

### 3.14 Reviews moderation (optional)


| Item             | Guidance                                                                                                                                                            |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View and moderate reviews; flag/hide/respond.                                                                                                                       |
| **Data to load** | Paginated reviews; detail with order context.                                                                                                                       |
| **API**          | Reviews list/detail per contract; filters: restaurant, rating range, date range. Hide/Flag/Approve via update endpoint.                                             |
| **Flow**         | Filters from select; open detail; Hide/Flag/Approve with optimistic update.                                                                                         |
| **UI**           | Table: restaurant, customer, order #, rating, snippet, created_at, actions (View, Hide/Flag, Respond). Detail: full text, order context, restaurant reply, actions. |


---

### 3.15 Roles and admin users (platform admin)


| Item             | Guidance                                                                                                                    |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Manage platform-level admin roles and users.                                                                                |
| **Data to load** | Roles list; admin users list; permissions (app-level).                                                                      |
| **API**          | App-level roles/permissions endpoints (not restaurant ERD).                                                                 |
| **Flow**         | List roles; add/edit role and assign permissions. List users; invite; assign role (role id from dropdown in state).         |
| **UI**           | Roles table; role detail with permission toggles; admin users table; invite form; role selector (labels only, id in state). |


---

## 4. Data ↔ API quick reference (Restaurant)


| Section                | Main API                                                                                                      |
| ---------------------- | ------------------------------------------------------------------------------------------------------------- |
| Overview               | `GET /api/v1/restaurant/dashboard/overview?restaurantId=`                                                     |
| Restaurants            | `GET/POST/PUT/DELETE /api/v1/restaurants`, operating-hours                                                    |
| Categories             | `GET/POST/PUT/DELETE /api/v1/categories`, filter by restaurantId                                              |
| Products               | `GET/POST/PUT/DELETE /api/v1/products`, filter by restaurantId, categoryId, isAvailable, lowStock, isFeatured |
| Orders                 | `GET/POST/PUT /api/v1/orders`, accept/reject endpoints                                                        |
| Offers                 | `GET/POST/PUT/DELETE /api/v1/offers`                                                                          |
| Promo codes            | `GET/POST/PUT/DELETE /api/v1/promo-codes`                                                                     |
| Disputes               | `GET/POST/PUT /api/v1/restaurant-order-disputes`                                                              |
| Documents              | `GET/PUT /api/v1/restaurant-documents` (verification_status)                                                  |
| Reputation & penalties | Via restaurants and reputation/penalty data                                                                   |
| Staff & roles          | Per-restaurant staff/roles endpoints                                                                          |
| Analytics              | Daily/monthly stats endpoints                                                                                 |
| Smart assistant        | restaurant_assistant_queries (or equivalent)                                                                  |
| Recurring orders       | restaurant_recurring_orders endpoints                                                                         |
| Reviews                | reviews list/detail (if in scope)                                                                             |
| Platform admin         | App-level roles/permissions                                                                                   |


---

## 5. Summary

- **15 sections;** each as separate route with loading, empty, error.
- **Filters/sort:** Select menus only; store id/enum in state; never show raw value to user.
- **POST:** Backend-known data in state; one input per user field. **PUT:** Optimistic update; revert on failure.
- **Pickup and dine-in only;** order types: Pickup, DineIn. Use [API_CONTRACT_RESTAURANTS.md](../docs/API_CONTRACT_RESTAURANTS.md) and [API_CONTRACT_CLIENT_BEHAVIOR.md](../docs/API_CONTRACT_CLIENT_BEHAVIOR.md).

