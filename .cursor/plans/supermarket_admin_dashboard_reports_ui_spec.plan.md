---

## Financial Reports

- **Purpose:** Provide financial visibility into platform revenue, service fees, commissions, and store-level financial performance.

- **Data (ERD):** `sm_orders` (subtotal, discount_amount, service_fee, total_amount, cancellation_fee_amount), `sm_commission_rules`, `sm_stores`, optional aggregated data from `sm_store_daily_stats`.

- **UI:**
  - **Revenue overview cards:** 
    - Total platform revenue (Today / Week / Month / Custom range)
    - Total service fees collected
    - Total commissions collected
    - Total cancellation fees
  - **Revenue breakdown:**
    - Revenue by store
    - Revenue by date range (daily aggregation)
    - Revenue by order status (Completed, Cancelled)
  - **Store financial table:**
    - Store name
    - Total orders
    - Gross sales
    - Total commission deducted
    - Net payable amount
  - **Filters:**
    - Date range
    - Store
    - Order status
  - **Export options:** CSV / Excel export for accounting purposes.

- **Notes:** Financial metrics must strictly follow finalized order states (e.g., Completed only for confirmed revenue). Cancellation fees should be tracked separately.

---

## Performance Analytics

- **Purpose:** Analyze operational and business performance indicators to support strategic decision-making.

- **Data (ERD):** `sm_orders`, `sm_order_items`, `sm_products`, `sm_store_daily_stats`, `sm_stores`.

- **UI:**
  - **Top-performing products:**
    - Most ordered products (by quantity)
    - Highest revenue-generating products
  - **Top-performing stores:**
    - Ranked by completed orders
    - Ranked by revenue
    - Ranked by lowest cancellation rate
  - **Operational metrics:**
    - Average basket value (Average order total)
    - Order completion rate
    - Cancellation rate (daily / weekly / monthly)
    - Average preparation time (Accepted → ReadyForPickup)
  - **Trend charts:**
    - Orders trend over time
    - Revenue trend over time
    - Cancellation trend over time
  - **Filters:**
    - Date range
    - Store
    - Product category

- **Notes:** Metrics should rely on clean order lifecycle states. Trends should allow comparison between time ranges (e.g., this week vs last week).

---

## Main Dashboard (Home Screen)

- **Purpose:** Central operational dashboard displaying aggregated KPIs and real-time activity snapshot.

- **Data (ERD):** `sm_orders`, `sm_stores`, `sm_order_disputes`, `sm_products`, `sm_store_daily_stats`.

- **UI:**
  - **Sales summary section:**
    - Total sales (Today / Week / Month)
    - Total commission revenue
    - Total service fees
  - **Activity metrics:**
    - Total number of orders
    - New users count
    - Active stores count
    - Orders pending pickup
  - **Operational alerts:**
    - Low stock products count
    - Stores with high cancellation rate
    - Open disputes count
  - **Quick access buttons:**
    - Go to Orders
    - Go to Stores
    - Go to Financial Reports
    - Go to Analytics
  - **Recent activity feed:**
    - Latest orders
    - Recently suspended stores
    - Recent disputes opened

- **Notes:** 
  - Critical alerts (low stock, disputes, high cancellation rate) must be visually emphasized.
  - This screen acts as the executive summary and should prioritize clarity over density.
  - Data refresh can be near real-time or periodic (based on backend capability).

---
