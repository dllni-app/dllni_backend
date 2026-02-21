# API Contract – Supermarket Admin Dashboard

**Audience:** Frontend / Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Endpoints required for supermarket admin dashboard:
- **Dashboard Overview:** KPIs, activity metrics, sales summary, operational alerts
- **Financial Reports:** Revenue breakdown by store and date, filtering by date range and status
- **Performance Analytics:** Top products, top stores, operational metrics, trends

For full CRUD, Auth, or other modules, see [API_CONTRACT_RESTAURANTS.md](API_CONTRACT_RESTAURANTS.md), [API_CONTRACT_CLEANING.md](API_CONTRACT_CLEANING.md), [API_CONTRACT_AUTH.md](API_CONTRACT_AUTH.md).

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **API prefix:** `https://dllni.mustafafares.com/api/v1/`
- **Auth:** Laravel Sanctum. Send token on every request:
  - Header: `Authorization: Bearer {token}`
- **Content-Type:** `application/json` for request bodies; responses are JSON.

---

## 2. Supermarket Admin Dashboard Overview

The admin dashboard displays:

1. **Sales Summary** – total revenue, commissions, service fees for today, this week, this month
2. **Activity Metrics** – total orders, active stores, pending pickup orders
3. **Operational Alerts** – low stock products, high cancellation stores, open disputes
4. **Recent Activity** – latest orders with customer and store information

---

## 3. Supermarket Admin endpoints

### 3.1 Dashboard overview (single request for KPIs)

| Method | Path                | Description                                                     |
| ------ | ------------------- | --------------------------------------------------------------- |
| GET    | `/api/v1/sm-dashboard` | KPIs for sales, activity, alerts, and recent activity          |

**Query params:** None

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/sm-dashboard
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "sales_summary": {
    "today": {
      "total_revenue": 45230.50,
      "total_commission_revenue": 2261.50,
      "total_service_fees": 1131.00
    },
    "this_week": {
      "total_revenue": 285640.00,
      "total_commission_revenue": 14282.00,
      "total_service_fees": 7141.00
    },
    "this_month": {
      "total_revenue": 1205300.00,
      "total_commission_revenue": 60265.00,
      "total_service_fees": 30132.50
    }
  },
  "activity_metrics": {
    "total_orders": 2845,
    "active_stores": 12,
    "total_stores": 15,
    "pending_pickup_orders": 8
  },
  "operational_alerts": {
    "low_stock_products_count": 0,
    "high_cancellation_stores_count": 1,
    "open_disputes_count": 2
  },
  "recent_activity": [
    {
      "id": 5234,
      "order_number": "ORD-5234",
      "customer_name": "John Doe",
      "store_name": "Store A",
      "total_amount": 456.75,
      "status": "completed",
      "created_at": "2025-02-21T14:30:00.000000Z"
    },
    {
      "id": 5233,
      "order_number": "ORD-5233",
      "customer_name": "Jane Smith",
      "store_name": "Store B",
      "total_amount": 234.50,
      "status": "pending",
      "created_at": "2025-02-21T14:15:00.000000Z"
    }
  ]
}
```

| Field                       | Type   | Description                                                      |
| --------------------------- | ------ | ---------------------------------------------------------------- |
| sales_summary               | object | Sales data grouped by time period (today, this_week, this_month) |
| sales_summary.*.total_revenue | number | Sum of completed orders in the period                             |
| sales_summary.*.total_commission_revenue | number | Commission earnings in the period                    |
| sales_summary.*.total_service_fees | number | Service fees collected in the period                  |
| activity_metrics            | object | Current activity snapshot                                        |
| activity_metrics.total_orders | number | Total orders in the system                                       |
| activity_metrics.active_stores | number | Number of stores with status `active`                            |
| activity_metrics.total_stores | number | Total number of stores                                           |
| activity_metrics.pending_pickup_orders | number | Orders awaiting customer pickup                      |
| operational_alerts          | object | Alert counts for admin attention                                 |
| operational_alerts.low_stock_products_count | number | Products at or below threshold                      |
| operational_alerts.high_cancellation_stores_count | number | Stores with high cancellation rates                |
| operational_alerts.open_disputes_count | number | Active disputes                                    |
| recent_activity             | array  | Latest 5 orders (id, order_number, customer_name, store_name, total_amount, status, created_at) |

---

### 3.2 Financial Reports

| Method | Path                           | Description                                          |
| ------ | ------------------------------ | ---------------------------------------------------- |
| GET    | `/api/v1/sm-reports/financial` | Financial breakdown by store and date with filters  |

**Query params:**

| Param         | Type    | Required | Description                                       |
| ------------- | ------- | -------- | ------------------------------------------------- |
| startDate     | string  | Yes      | Start date (YYYY-MM-DD format)                    |
| endDate       | string  | Yes      | End date (YYYY-MM-DD format), must be >= startDate |
| storeId       | integer | No       | Filter by specific store (exists:sm_stores,id)    |
| status        | string  | No       | Filter by order status: pending, accepted, ready_for_pickup, completed, cancelled |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/sm-reports/financial?startDate=2025-02-01&endDate=2025-02-21&storeId=1
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "overview": {
    "total_revenue": 125600.00,
    "total_service_fees": 6280.00,
    "total_commissions": 3140.00,
    "total_cancellation_fees": 500.00,
    "period": {
      "start_date": "2025-02-01",
      "end_date": "2025-02-21"
    }
  },
  "by_store": [
    {
      "store_id": 1,
      "store_name": "Store A",
      "total_revenue": 125600.00,
      "total_service_fees": 6280.00,
      "total_commissions": 3140.00,
      "total_cancellation_fees": 500.00,
      "order_count": 245,
      "average_order_value": 512.65
    },
    {
      "store_id": 2,
      "store_name": "Store B",
      "total_revenue": 95300.00,
      "total_service_fees": 4765.00,
      "total_commissions": 2382.50,
      "total_cancellation_fees": 200.00,
      "order_count": 186,
      "average_order_value": 512.37
    }
  ],
  "by_date": [
    {
      "date": "2025-02-21",
      "total_revenue": 12450.00,
      "total_service_fees": 622.50,
      "total_commissions": 311.25,
      "order_count": 24
    },
    {
      "date": "2025-02-20",
      "total_revenue": 11820.00,
      "total_service_fees": 591.00,
      "total_commissions": 295.50,
      "order_count": 23
    }
  ]
}
```

| Field                  | Type   | Description                                         |
| ---------------------- | ------ | --------------------------------------------------- |
| overview               | object | Aggregate totals for the date range                 |
| overview.total_revenue | number | Sum of order totals                                 |
| overview.total_service_fees | number | Sum of service fees                            |
| overview.total_commissions | number | Sum of commissions earned                     |
| overview.total_cancellation_fees | number | Fees from cancelled orders                   |
| overview.period        | object | Date range queried                                  |
| by_store               | array  | Breakdown per store (store_id, store_name, all overview fields plus order_count, average_order_value) |
| by_date                | array  | Daily breakdown (date, total_revenue, total_service_fees, total_commissions, order_count) |

---

### 3.3 Performance Analytics

| Method | Path                             | Description                                          |
| ------ | -------------------------------- | ---------------------------------------------------- |
| GET    | `/api/v1/sm-reports/performance` | Performance metrics, top products, top stores, trends |

**Query params:**

| Param     | Type    | Required | Description                                       |
| --------- | ------- | -------- | ------------------------------------------------- |
| startDate | string  | Yes      | Start date (YYYY-MM-DD format)                    |
| endDate   | string  | Yes      | End date (YYYY-MM-DD format), must be >= startDate |
| storeId   | integer | No       | Filter by specific store (exists:sm_stores,id)    |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/sm-reports/performance?startDate=2025-02-01&endDate=2025-02-21
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "top_products": [
    {
      "product_id": 42,
      "product_name": "Premium Sandwich",
      "quantity_sold": 356,
      "order_count": 234,
      "total_revenue": 8930.00
    },
    {
      "product_id": 37,
      "product_name": "Fresh Juice",
      "quantity_sold": 289,
      "order_count": 195,
      "total_revenue": 5780.00
    }
  ],
  "top_stores": [
    {
      "store_id": 1,
      "store_name": "Store A",
      "total_revenue": 125600.00,
      "order_count": 245,
      "completed_count": 237,
      "cancelled_count": 8,
      "completion_rate": 96.73
    },
    {
      "store_id": 3,
      "store_name": "Store C",
      "total_revenue": 98700.00,
      "order_count": 192,
      "completed_count": 185,
      "cancelled_count": 7,
      "completion_rate": 96.35
    }
  ],
  "operational_metrics": {
    "average_basket_value": 512.45,
    "completion_rate": 96.5,
    "cancellation_rate": 3.5,
    "total_orders": 431
  },
  "trends": [
    {
      "date": "2025-02-21",
      "order_count": 24,
      "total_revenue": 12450.00,
      "completion_rate": 95.8
    },
    {
      "date": "2025-02-20",
      "order_count": 23,
      "total_revenue": 11820.00,
      "completion_rate": 97.1
    }
  ],
  "period": {
    "start_date": "2025-02-01",
    "end_date": "2025-02-21"
  }
}
```

| Field                 | Type   | Description                                                |
| --------------------- | ------ | ---------------------------------------------------------- |
| top_products          | array  | Top 10 products by quantity (product_id, product_name, quantity_sold, order_count, total_revenue) |
| top_stores            | array  | Top 10 stores by revenue (store_id, store_name, total_revenue, order_count, completed_count, cancelled_count, completion_rate) |
| operational_metrics   | object | KPIs for the date range                                    |
| operational_metrics.average_basket_value | number | Mean order total                                |
| operational_metrics.completion_rate | number | Percent of non-cancelled orders (0–100)           |
| operational_metrics.cancellation_rate | number | Percent of cancelled orders (0–100)               |
| operational_metrics.total_orders | number | Total orders in period                           |
| trends                | array  | Daily metrics (date, order_count, total_revenue, completion_rate) |
| period                | object | Date range queried                                         |

---

## 4. Order status values

Use these when filtering or displaying status:

| Value              | Description              |
| ------------------ | ----------------------- |
| `pending`          | New, awaiting acceptance |
| `accepted`         | Accepted by store       |
| `ready_for_pickup` | Ready for customer pickup |
| `completed`        | Completed/picked up     |
| `cancelled`        | Cancelled               |

---

## 5. Date filtering notes

- Both `startDate` and `endDate` are required on report endpoints
- Dates must be in `YYYY-MM-DD` format
- `endDate` must be >= `startDate` (validation error returns 422 with `endDate` in errors)
- Date ranges can span multiple months
- For dashboard (`/api/v1/sm-dashboard`), no date filtering is needed; it returns aggregated stats for today, this week, this month automatically

---

## 6. Optional filtering

The financial and performance endpoints support optional filtering:

- **By Store:** Pass `storeId` to scope results to a single store
- **By Status** (financial only): Pass `status` to filter orders by completion status

All filters are optional; omit them for unfiltered results across all stores/statuses.

---

## 7. Pagination and sorting

Report endpoints return results as arrays without pagination. For large date ranges, results include:

- `by_store` array: up to N stores (sorted by revenue descending)
- `by_date` array: one entry per day in the range (sorted by date descending)
- `top_products` array: up to 10 products (sorted by quantity descending)
- `top_stores` array: up to 10 stores (sorted by revenue descending)

---

## 8. Error responses

- **401 Unauthorized:** Missing or invalid token. Redirect to login.
  ```json
  {
    "message": "Unauthenticated."
  }
  ```

- **422 Unprocessable Entity:** Validation errors (e.g. invalid date format, endDate < startDate, invalid storeId).
  ```json
  {
    "message": "The given data was invalid.",
    "errors": {
      "endDate": ["The end date must be after or equal to the start date."]
    }
  }
  ```

- **404 Not Found:** Resource not found.
  ```json
  {
    "message": "Not found."
  }
  ```

- **403 Forbidden:** User not allowed to access protected resources.
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```

---

## 9. Example client implementations

### 9.1 Fetch dashboard overview (JavaScript/React)

```javascript
const fetchDashboard = async (token) => {
  const response = await fetch(
    'https://dllni.mustafafares.com/api/v1/sm-dashboard',
    {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    }
  );

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }

  return response.json();
};
```

### 9.2 Fetch financial report with filters (JavaScript/React)

```javascript
const fetchFinancialReport = async (token, startDate, endDate, storeId = null) => {
  const params = new URLSearchParams({
    startDate,
    endDate,
    ...(storeId && { storeId }),
  });

  const response = await fetch(
    `https://dllni.mustafafares.com/api/v1/sm-reports/financial?${params}`,
    {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Failed to fetch report');
  }

  return response.json();
};
```

### 9.3 Fetch performance analytics (Dart/Flutter)

```dart
Future<Map<String, dynamic>> fetchPerformanceAnalytics({
  required String token,
  required String startDate,
  required String endDate,
  int? storeId,
}) async {
  String url = 'https://dllni.mustafafares.com/api/v1/sm-reports/performance'
    '?startDate=$startDate&endDate=$endDate';

  if (storeId != null) {
    url += '&storeId=$storeId';
  }

  final response = await http.get(
    Uri.parse(url),
    headers: {
      'Authorization': 'Bearer $token',
      'Content-Type': 'application/json',
    },
  );

  if (response.statusCode != 200) {
    throw Exception('Failed to fetch analytics: ${response.statusCode}');
  }

  return jsonDecode(response.body);
}
```

---

## 10. Rate limiting and best practices

- **Rate Limiting:** No specific limits documented; assume standard API rate limits may apply.
- **Caching:** Dashboard data is fresh; consider client-side caching for 1–5 minutes.
- **Date Range:** Limit financial/performance queries to reasonable ranges (e.g., max 90 days) for performance.
- **Token Refresh:** Refresh your Sanctum token periodically if the session expires; 401 responses indicate re-authentication is required.
