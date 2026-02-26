---
name: Flutter Supermarket Admin Dashboard Implementation
overview: Flutter developer implementation plan for the Supermarket module admin dashboard — UI best practices, data handling, and page-by-page data & flow guide. References supermarket_admin_dashboard_ui_spec, supermarket_admin_dashboard_reports_ui_spec, and API_CONTRACT_SUPERMARKET_ADMIN.
todos: []
isProject: false
---

# Flutter – Supermarket Admin Dashboard Implementation Plan

**Audience:** Flutter developer  
**UI spec:** [supermarket_admin_dashboard_ui_spec.plan.md](.cursor/plans/supermarket_admin_dashboard_ui_spec.plan.md)  
**Reports spec:** [supermarket_admin_dashboard_reports_ui_spec.plan.md](.cursor/plans/supermarket_admin_dashboard_reports_ui_spec.plan.md)  
**API contract:** [docs/API_CONTRACT_SUPERMARKET_ADMIN.md](../docs/API_CONTRACT_SUPERMARKET_ADMIN.md)  
**Client behavior:** [docs/API_CONTRACT_CLIENT_BEHAVIOR.md](../docs/API_CONTRACT_CLIENT_BEHAVIOR.md)

---

## 1. Best practices – UI implementation

- **Layout:** Responsive: single column on mobile, cards + tables on larger screens. Use `LayoutBuilder` or responsive wrapper. **Pickup-only** (no delivery).
- **Loading / empty / error:** Every screen: (1) loading (shimmer/skeleton), (2) empty (illustration + CTA), (3) error (message + retry). Never show raw IDs/enums; use **select menus** and labels only (per client behavior).
- **Status badges:** Use `SmOrderStatus` (Pending, Accepted, Preparing, ReadyForPickup, Completed, Cancelled) and dispute/document enums; localized labels and consistent colors.
- **Alerts:** Low-stock, high-cancellation stores, and open-dispute counts must be **visually emphasized** when > 0 (e.g. warning/error style). Critical alerts (disputes, low stock, high cancellation) should stand out on the main dashboard.
- **Charts:** Use a single chart library (e.g. fl_chart, charts_flutter); keep trend charts readable (axis labels, legend). Revenue/order metrics must follow finalized order states (e.g. Completed for confirmed revenue).
- **Accessibility:** Contrast, touch targets ≥ 44pt, semantic labels; support larger text.
- **RTL:** Support RTL for Arabic where layout depends on direction.

---

## 2. Data handling

- **Auth:** Store token; send `Authorization: Bearer {token}`. Base URL: `https://dllni.mustafafares.com`, prefix: `/api/v1/`.
- **Pagination:** List endpoints use `data`, `links`, `meta`; `perPage`, `page`. Report endpoints (financial, performance) return arrays without pagination; date range drives size.
- **Filters & sort:** `filter[fieldName]=value`, `sort=field`/`sort=-field` (camelCase). Filter values (status, storeId, etc.) from **select menus**; store selected id/enum in page state; do not show raw value to user.
- **Date range:** Reports require `startDate` and `endDate` (YYYY-MM-DD); `endDate` >= `startDate`. Store in state; use date pickers (labels only for user).
- **POST:** Backend-known data (e.g. storeId from context) in page state only; one dedicated input per user field.
- **PUT:** Optimistic update → send request → update UI; on success keep, on failure revert and show error.
- **Errors:** Parse 401 (redirect to login), 422 (`errors` by field), 403/404; generic message + retry for network/server errors.
- **Caching:** Short cache for main dashboard; pull-to-refresh; invalidate lists on create/update/delete. Reports: optional cache per date range/store.

---

## 3. Page-by-page data & flow guide

### 3.1 Live overview / command center (main dashboard)


| Item             | Guidance                                                                                                                                                                                                                                                                                                                               |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | First screen after login; sales summary, activity metrics, operational alerts, recent activity, quick links.                                                                                                                                                                                                                           |
| **Data to load** | Single request: sales summary (today, this week, this month), activity metrics (total orders, active stores, pending pickup), operational alerts (low stock count, high cancellation stores, open disputes), recent activity (latest orders).                                                                                          |
| **API**          | `GET /api/v1/sm-dashboard` (no query params).                                                                                                                                                                                                                                                                                          |
| **Flow**         | On load → loading skeleton → success: render sales summary cards, activity metrics, alerts (emphasize if counts > 0), recent activity list, quick links (Orders, Stores, Financial Reports, Analytics). Error → retry. Pull-to-refresh.                                                                                                |
| **UI**           | Sales summary: Total sales (Today / Week / Month), commission revenue, service fees. Activity: total orders, active stores, pending pickup. Alerts: low stock count, high cancellation stores, open disputes (visually prominent). Quick links. Recent activity: latest orders (order #, customer, store, amount, status, created_at). |


---

### 3.2 Financial reports


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                                   |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Revenue, service fees, commissions, cancellation fees; by store and by date; store financial table; export.                                                                                                                                                                                                                                                |
| **Data to load** | Date range (required); optional store filter. Revenue overview, by_store, by_date; store financial table (gross sales, commission, net payable).                                                                                                                                                                                                           |
| **API**          | `GET /api/v1/sm-reports/financial?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD` + optional `storeId`, `status`. Response: overview, by_store, by_date.                                                                                                                                                                                                          |
| **Flow**         | User selects date range (required); store filter from dropdown (store id in state). Load report; display overview cards, revenue by store, by date (table/chart). Store financial table: store name, total orders, gross sales, commission, net payable. Export: build CSV/Excel from response (client-side or dedicated export endpoint if available).    |
| **UI**           | Date range picker (required); store filter (optional). Revenue overview cards (total revenue, service fees, commissions, cancellation fees). Revenue breakdown: by store table/list; by date (daily aggregation) table or chart. Store financial table: store name, total orders, gross sales, commission deducted, net payable. Export: CSV/Excel button. |


---

### 3.3 Performance analytics


| Item             | Guidance                                                                                                                                                                                                                                                                                                                                  |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Top products, top stores, operational metrics, trend charts.                                                                                                                                                                                                                                                                              |
| **Data to load** | Date range (required); optional store filter. Top products (quantity, revenue); top stores (orders, revenue, completion/cancellation rate); operational metrics; trends (orders, revenue, cancellation over time).                                                                                                                        |
| **API**          | `GET /api/v1/sm-reports/performance?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD` + optional `storeId`. Response: top_products, top_stores, operational_metrics, trends, period.                                                                                                                                                               |
| **Flow**         | Date range required; optional store from dropdown (id in state). Load → render metrics, top products table, top stores table, trend charts. Compare time ranges (e.g. this week vs last week) if UI supports: two requests or backend support.                                                                                            |
| **UI**           | Date range; store filter. Top products: most ordered (quantity), highest revenue. Top stores: by completed orders, by revenue, by lowest cancellation rate. Operational metrics: average basket value, order completion rate, cancellation rate, avg preparation time (if in API). Trend charts: orders, revenue, cancellation over time. |


---

### 3.4 Stores management


| Item             | Guidance                                                                                                                                                                                                                                                                                                                          |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List, verify, suspend stores; documents and trust.                                                                                                                                                                                                                                                                                |
| **Data to load** | Paginated stores; detail with hours, documents, trust log, daily stats.                                                                                                                                                                                                                                                           |
| **API**          | List: `GET /api/v1/sm-stores` (or equivalent) + filters. Detail: `GET /api/v1/sm-stores/{id}` with relations. Documents: list in detail; Approve/Reject via update document endpoint.                                                                                                                                             |
| **Flow**         | Filters (active, featured, trust range, search) from select; store id in state. Detail: id from route. Documents: verification_status update (optimistic). Suspend/Activate: update store (optimistic).                                                                                                                           |
| **UI**           | Table: name, slug, owner, address, phone, average_rating, total_reviews, trust_score, warning_count, is_active, is_featured, suspension_until, actions (View, Suspend/Activate, Verify documents, View trust log). Detail: store info, trust card + log, documents (type, verification_status, View/Approve/Reject), daily stats. |


---

### 3.5 Categories and products (catalog)


| Item             | Guidance                                                                                                                                                                                                                                                                                            |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View/moderate catalog; low stock and expiry alerts.                                                                                                                                                                                                                                                 |
| **Data to load** | Store selector; categories; products with filters (store, category, isAvailable, lowStock, expiring soon, source_type).                                                                                                                                                                             |
| **API**          | Categories: list by store. Products: list + `filter[storeId]`, `filter[categoryId]`, `filter[isAvailable]`, `filter[lowStock]`, etc. Product detail: full fields, inventory log if available. Toggle availability: PUT product.                                                                     |
| **Flow**         | Store from dropdown; category filter from select (categories loaded by store). Product list and detail; optimistic update for availability.                                                                                                                                                         |
| **UI**           | Store selector; categories list; products table: name, store, category, barcode, price, discounted_price, stock_quantity, low_stock_threshold, expires_at, is_available, source_type, actions (View, Toggle availability, Inventory log). Detail: full product, inventory log, master_product link. |


---

### 3.6 Orders management


| Item             | Guidance                                                                                                                                                                                                                                                                                  |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | List orders (pickup-only), filter, view timeline and pickup state, disputes.                                                                                                                                                                                                              |
| **Data to load** | Paginated orders; detail with items, status logs, pickup timestamps.                                                                                                                                                                                                                      |
| **API**          | List: `GET /api/v1/sm-orders` (or equivalent) + `filter[status]`, `filter[storeId]`, `filter[dateFrom]`, `filter[dateTo]`, `filter[pickupMode]`, `filter[hasDispute]`. Detail: `GET /api/v1/sm-orders/{id}`. Status change: PUT order.                                                    |
| **Flow**         | Filters from select/date pickers; status values from enum (select menu, id/value in state). Detail: order id from route. Optimistic update for status.                                                                                                                                    |
| **UI**           | Table: Order #, customer, store, status, pickup_mode, pickup_scheduled_for, ready_for_pickup_at, picked_up_at, total_amount, has dispute, created_at, actions (View, Change status, Open dispute). Detail: full order fields, items, status timeline, pickup timestamps, link to dispute. |


---

### 3.7 Offers and promotions


| Item             | Guidance                                                                                                                                                             |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View and moderate store offers and product links.                                                                                                                    |
| **Data to load** | Paginated offers; detail with products.                                                                                                                              |
| **API**          | List: offers + `filter[storeId]`, `filter[isActive]`, date range. Detail: offer + products. Activate/Deactivate: PUT offer.                                          |
| **Flow**         | Store and active/date filters from select; detail for product list. Optimistic update for activate/deactivate.                                                       |
| **UI**           | Table: store, offer name, type, starts_at, ends_at, is_active, linked products count, actions (View, Deactivate/Activate). Detail: offer fields + products in offer. |


---

### 3.8 Coupons management


| Item             | Guidance                                                                                                                                         |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | View and manage coupons (validity, usage).                                                                                                       |
| **Data to load** | Paginated coupons; detail; optional usage count.                                                                                                 |
| **API**          | List: coupons + filters (store, active, date range). Detail: coupon config; orders that used it if available. Update/Deactivate: PUT.            |
| **Flow**         | Filters from select; optimistic update for edits.                                                                                                |
| **UI**           | Table: code, store, type, value, starts_at, ends_at, is_active, usage count, actions (View, Deactivate, Edit). Detail: full config, orders used. |


---

### 3.9 Order disputes and support tickets


| Item             | Guidance                                                                                                                                                                                                                                                        |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Resolve order disputes; message thread and actions.                                                                                                                                                                                                             |
| **Data to load** | Paginated disputes; detail with messages and order summary.                                                                                                                                                                                                     |
| **API**          | List: `GET /api/v1/sm-order-disputes` (or equivalent) + `filter[status]`, `filter[storeId]`, `filter[dateFrom]`, `filter[dateTo]`. Detail: dispute + messages + order. Reply: POST message; Resolve/Close: PUT dispute.                                         |
| **Flow**         | Filters from select; detail loads messages and order. Reply: user input → POST; Resolve/Close: confirm → PUT. Optimistic update.                                                                                                                                |
| **UI**           | Table: ticket #, order #, customer, store, status (Open, UnderReview, Resolved, Closed), opened at, actions (View, Reply, Resolve, Close). Detail: complaint, message thread, order summary, actions (Refund partial, Deduct from store, Close, change status). |


---

### 3.10 Store documents and verification


| Item             | Guidance                                                                                                                                                                                                                                      |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Bulk review and approve/reject store documents.                                                                                                                                                                                               |
| **Data to load** | Paginated documents; filters.                                                                                                                                                                                                                 |
| **API**          | List: store documents + `filter[storeId]`, `filter[documentType]`, `filter[verificationStatus]`. Detail: document preview. Approve/Reject: PUT document (verification_status, optional note).                                                 |
| **Flow**         | Filters from select; Approve/Reject with optional note; document id in state. Optimistic update.                                                                                                                                              |
| **UI**           | Table: store name, document type (Identity, CommercialRegistration, HealthCertificate, Other), verification_status, submitted at, expiry, actions (View, Approve, Reject). Detail: preview/download, store context, Approve/Reject with note. |


---

### 3.11 Trust and compliance (store reputation)


| Item             | Guidance                                                                                                                                                                       |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Purpose**      | Monitor trust score and warning count; trust log; suspension.                                                                                                                  |
| **Data to load** | Stores with trust/warning; trust log per store.                                                                                                                                |
| **API**          | Stores list/detail (trust_score, warning_count); trust log from store detail or dedicated endpoint. Suspend: PUT store.                                                        |
| **Flow**         | List sorted/filtered by trust; open store for log. Suspend from detail; optimistic update.                                                                                     |
| **UI**           | Stores by trust (score, warning_count, last change); actions: View log, Suspend. Per-store: trust log entries. Optional: automation rules (e.g. threshold config) if in scope. |


---

### 3.12 Commission rules


| Item             | Guidance                                                                                                                                                                                     |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View and configure commission rules per store.                                                                                                                                               |
| **Data to load** | Commission rules list (by store); detail/form for add/edit.                                                                                                                                  |
| **API**          | List: commission rules + filter by store. Create/Update: POST/PUT with storeId, type (Percentage/Fixed), value, is_default, is_active. Store id from selector (dropdown label, id in state). |
| **Flow**         | Store selector for list; add/edit form: store from dropdown (id in state), type and value from user inputs. Validation: one default per store if applicable. Optimistic update.              |
| **UI**           | List: store, rule type, value, is_default, is_active, actions (View, Edit, Set default, Deactivate). Form: store selector, commission type (Percentage/Fixed), value, is_default, active.    |


---

### 3.13 Analytics and daily stats


| Item             | Guidance                                                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Store performance over time (orders, revenue).                                                                            |
| **Data to load** | Store selector + date range; daily (or weekly) stats.                                                                     |
| **API**          | Use `sm_store_daily_stats` or equivalent (store_id, date, metrics). Optional: combine with performance report for trends. |
| **Flow**         | Store and date range from selectors; load stats; optional export.                                                         |
| **UI**           | Store selector; date range; charts/tables (order count, revenue); export CSV/Excel if supported.                          |


---

### 3.14 Smart assistant and recipe usage (analytics)


| Item             | Guidance                                                                                                  |
| ---------------- | --------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Monitor assistant and recipe-based queries (read-only).                                                   |
| **Data to load** | Summary (queries today/week, by input mode, by store); table of queries.                                  |
| **API**          | Assistant queries endpoint + filters (store, date range, has_recipe_match).                               |
| **Flow**         | Load summary and list; filters from select. No PII beyond admin allowance.                                |
| **UI**           | Summary cards; table: user (or anonymized), store, input preview, input_mode, matched_recipe, created_at. |


---

### 3.15 Recurring orders (monitoring)


| Item             | Guidance                                                                                                                                                      |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | View and optionally pause/cancel recurring orders.                                                                                                            |
| **Data to load** | Paginated recurring orders; detail with items and next run.                                                                                                   |
| **API**          | Recurring orders list/detail + filters (status, store, date range).                                                                                           |
| **Flow**         | List with filters; detail for config and items; pause/cancel if allowed (optimistic).                                                                         |
| **UI**           | Table: customer, store, status, frequency/next_run_at, items count, created_at, actions (View). Detail: config, items, next run, history of generated orders. |


---

### 3.16 Roles and admin users


| Item             | Guidance                                                                                                                    |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------- |
| **Purpose**      | Manage platform-level admin roles and users.                                                                                |
| **Data to load** | Roles list; admin users list; permissions (app-level).                                                                      |
| **API**          | App-level roles/permissions endpoints.                                                                                      |
| **Flow**         | List roles; add/edit role and assign permissions. List users; invite; assign role (role id from dropdown in state).         |
| **UI**           | Roles table; role detail with permission toggles; admin users table; invite form; role selector (labels only, id in state). |


---

## 4. Data ↔ API quick reference (Supermarket)


| Section                 | Main API                                                                          |
| ----------------------- | --------------------------------------------------------------------------------- |
| Main dashboard          | `GET /api/v1/sm-dashboard`                                                        |
| Financial reports       | `GET /api/v1/sm-reports/financial?startDate=&endDate=` + optional storeId, status |
| Performance analytics   | `GET /api/v1/sm-reports/performance?startDate=&endDate=` + optional storeId       |
| Stores                  | sm-stores list/detail, documents, trust                                           |
| Categories & products   | Categories and products by store, filters                                         |
| Orders                  | sm-orders list/detail, status filters                                             |
| Offers                  | sm-offers list/detail                                                             |
| Coupons                 | sm-coupons list/detail                                                            |
| Disputes                | sm-order-disputes list/detail, messages                                           |
| Store documents         | List/detail, verification_status update                                           |
| Trust & compliance      | Stores + trust log, suspend                                                       |
| Commission rules        | sm_commission_rules list/create/update                                            |
| Analytics / daily stats | sm_store_daily_stats or report endpoints                                          |
| Smart assistant         | sm_assistant_queries (or equivalent)                                              |
| Recurring orders        | sm_recurring_orders list/detail                                                   |
| Roles                   | App-level roles/permissions                                                       |


---

## 5. Summary

- **Main dashboard** (3.1) is the executive summary; **Financial** (3.2) and **Performance** (3.3) reports use date range (required) and optional store filter.
- **14+ sections** (overview, reports, stores, catalog, orders, offers, coupons, disputes, documents, trust, commission, analytics, assistant, recurring, roles); each with loading, empty, error.
- **Filters/sort:** Select menus only; store id/enum in state; never show raw value to user.
- **POST:** Backend-known data in state; one input per user field. **PUT:** Optimistic update; revert on failure.
- **Revenue metrics:** Use finalized order states (e.g. Completed for confirmed revenue); cancellation fees tracked separately.
- **Alerts:** Low stock, high cancellation stores, open disputes must be visually emphasized on main dashboard.
- Use [API_CONTRACT_SUPERMARKET_ADMIN.md](../docs/API_CONTRACT_SUPERMARKET_ADMIN.md) and [API_CONTRACT_CLIENT_BEHAVIOR.md](../docs/API_CONTRACT_CLIENT_BEHAVIOR.md).

