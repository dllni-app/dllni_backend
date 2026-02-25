# API Contract - Supermarket Store Owner

**Audience:** Frontend / Flutter developer
**Domain:** `dllni.mustafafares.com`
**Scope:** Endpoints required for supermarket store owner dashboard and operations:
- **Dashboard Overview:** Today metrics for the owner's store
- **Order Actions:** Accept or reject orders
- **Store Management:** View and update store profile
- **Product CRUD:** Use existing Supermarket product endpoints

For admin reports, see [API_CONTRACT_SUPERMARKET_ADMIN.md](API_CONTRACT_SUPERMARKET_ADMIN.md).
For Auth or other modules, see [API_CONTRACT_AUTH.md](API_CONTRACT_AUTH.md).

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **API prefix:** `https://dllni.mustafafares.com/api/v1/`
- **Auth:** Laravel Sanctum. Send token on every request:
  - Header: `Authorization: Bearer {token}`
- **Content-Type:** `application/json` for request bodies; responses are JSON.

---

## 2. Store Owner Dashboard Overview

The store owner dashboard displays:

1. **Order Metrics** - total orders, completed orders, new orders, pending orders
2. **Sales Summary** - total sales for completed orders (today)
3. **Order Lists** - detailed data for new orders and pending orders

---

## 3. Store Owner endpoints

### 3.1 Dashboard overview (today only)

| Method | Path                               | Description                          |
| ------ | ---------------------------------- | ------------------------------------ |
| GET    | `/api/v1/store-owner/dashboard`    | Today metrics for a single store     |

**Query params:**

| Param  | Type    | Required | Description                       |
| ------ | ------- | -------- | --------------------------------- |
| storeId | integer | Yes      | Store id (exists:sm_stores,id)    |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/store-owner/dashboard?storeId=1
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "message": "Dashboard data retrieved successfully.",
  "data": {
    "totalOrders": 20,
    "completedOrders": 7,
    "newOrders": 5,
    "pendingOrders": 8,
    "totalSales": 4523.75,
    "newOrdersData": [
      {
        "id": 201,
        "orderNumber": "ORD-201",
        "status": "pending",
        "totalAmount": 150.50,
        "customer": {
          "id": 3,
          "name": "John Doe"
        },
        "items": [
          {
            "id": 1,
            "productId": 45,
            "quantity": 2
          }
        ],
        "createdAt": "2025-02-21T14:30:00.000000Z"
      }
    ],
    "pendingOrdersData": [
      {
        "id": 202,
        "orderNumber": "ORD-202",
        "status": "accepted",
        "totalAmount": 225.00,
        "customer": {
          "id": 4,
          "name": "Jane Smith"
        },
        "items": [
          {
            "id": 2,
            "productId": 19,
            "quantity": 1
          }
        ],
        "createdAt": "2025-02-21T14:15:00.000000Z"
      }
    ]
  }
}
```

| Field                  | Type   | Description                                                         |
| ---------------------- | ------ | ------------------------------------------------------------------- |
| totalOrders            | number | Total orders created today                                          |
| completedOrders        | number | Orders completed today                                              |
| newOrders              | number | Orders with status `pending` created today                          |
| pendingOrders          | number | Orders not `completed` and not `cancelled` created today            |
| totalSales             | number | Sum of `total_amount` for completed orders today                    |
| newOrdersData          | array  | Detailed list of new orders (pending)                               |
| pendingOrdersData      | array  | Detailed list of all pending orders (non-completed, non-cancelled)  |

---

### 3.2 Accept order

| Method | Path                                         | Description                    |
| ------ | -------------------------------------------- | ------------------------------ |
| POST   | `/api/v1/store-owner/orders/{order}/accept`  | Accept an order                |

**Example request:**

```
POST https://dllni.mustafafares.com/api/v1/store-owner/orders/123/accept
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "message": "Order accepted successfully.",
  "data": {
    "id": 123,
    "orderNumber": "ORD-123",
    "status": "accepted",
    "storeId": 1,
    "customerId": 10,
    "items": [
      {
        "id": 1,
        "productId": 45,
        "quantity": 2
      }
    ],
    "createdAt": "2025-02-21T14:30:00.000000Z"
  }
}
```

---

### 3.3 Reject order

| Method | Path                                        | Description                      |
| ------ | ------------------------------------------- | -------------------------------- |
| POST   | `/api/v1/store-owner/orders/{order}/reject` | Reject an order                  |

**Body params:**

| Param               | Type   | Required | Description                         |
| ------------------- | ------ | -------- | ----------------------------------- |
| cancellationReason  | string | Yes      | Reason for rejecting the order      |

**Example request:**

```
POST https://dllni.mustafafares.com/api/v1/store-owner/orders/123/reject
Authorization: Bearer {token}
Content-Type: application/json

{
  "cancellationReason": "Out of stock for requested items"
}
```

**Response (200):**

```json
{
  "message": "Order rejected successfully.",
  "data": {
    "id": 123,
    "orderNumber": "ORD-123",
    "status": "cancelled",
    "cancellationReason": "Out of stock for requested items",
    "cancelledAt": "2025-02-21T15:00:00.000000Z"
  }
}
```

---

### 3.4 Store details

| Method | Path                                    | Description               |
| ------ | --------------------------------------- | ------------------------- |
| GET    | `/api/v1/store-owner/stores/{store}`    | Retrieve store details    |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/store-owner/stores/1
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "data": {
    "id": 1,
    "name": "My Store",
    "slug": "my-store",
    "description": "Fresh groceries",
    "phone": "+1234567890",
    "isActive": true,
    "createdAt": "2025-02-01T09:00:00.000000Z",
    "updatedAt": "2025-02-20T10:30:00.000000Z",
    "owner": {
      "id": 5,
      "name": "Store Owner"
    }
  }
}
```

---

### 3.5 Update store

| Method | Path                                    | Description            |
| ------ | --------------------------------------- | ---------------------- |
| PUT    | `/api/v1/store-owner/stores/{store}`    | Update store data      |

**Example request:**

```
PUT https://dllni.mustafafares.com/api/v1/store-owner/stores/1
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Updated Store Name",
  "description": "Updated description",
  "phone": "+1234567890"
}
```

**Response (200):**

```json
{
  "data": {
    "id": 1,
    "name": "Updated Store Name",
    "slug": "updated-store-name",
    "description": "Updated description",
    "phone": "+1234567890",
    "updatedAt": "2025-02-21T16:00:00.000000Z"
  }
}
```

---

### 3.6 Product CRUD (existing Supermarket endpoints)

Store owners can use the existing Supermarket product endpoints. These are already defined in the project:

| Method | Path                       | Description                 |
| ------ | -------------------------- | --------------------------- |
| GET    | `/api/v1/sm-products`      | List products               |
| POST   | `/api/v1/sm-products`      | Create product              |
| GET    | `/api/v1/sm-products/{id}` | Get product                 |
| PUT    | `/api/v1/sm-products/{id}` | Update product              |
| DELETE | `/api/v1/sm-products/{id}` | Delete product              |

Use the same payload/response structure as defined in the Supermarket product API.

---

## 4. Order status values

Use these when filtering or displaying status:

| Value              | Description                   |
| ------------------ | ---------------------------- |
| `pending`          | New, awaiting acceptance     |
| `accepted`         | Accepted by store            |
| `preparing`        | Store is preparing order     |
| `ready_for_pickup` | Ready for customer pickup    |
| `completed`        | Completed / picked up        |
| `cancelled`        | Cancelled                    |

---

## 5. Error responses

- **401 Unauthorized:** Missing or invalid token.
  ```json
  {
    "message": "Unauthenticated."
  }
  ```

- **422 Unprocessable Entity:** Validation errors (e.g. missing storeId, invalid cancellationReason).
  ```json
  {
    "message": "The given data was invalid.",
    "errors": {
      "storeId": ["The store id field is required."]
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
