# Restaurant Order Status API Contract for Flutter

## Purpose

This file explains the current backend API contract for changing and reading restaurant order statuses.

It is intended for the restaurant owner Flutter app and backend/API developers.

## Base URL

All endpoints are relative to:

```http
/api/v1
```

## Required Headers

```http
Authorization: Bearer {restaurantOwnerToken}
Accept: application/json
Content-Type: application/json
```

## Current Backend Status Summary

The backend currently has explicit action endpoints for:

- Accepting a restaurant order.
- Rejecting a restaurant order.

There is no dedicated generic endpoint like:

```http
PATCH /api/v1/restaurant-owner/orders/{orderId}/status
```

There is also no dedicated restaurant-owner action endpoint currently found for:

- Mark as preparing.
- Mark as ready for pickup.
- Mark as picked up.
- Mark as completed.

The generic `orders` resource update route can technically update the `status` field, but it should not be used by Flutter as a simple status-change endpoint because the request validation expects the full order payload and required fields such as `userId`, `restaurantId`, `orderNumber`, `subtotal`, and `totalAmount`.

## Order Status Enums

Use these exact backend enum values:

| Status | Meaning | Arabic UI Label Suggestion |
|---|---|---|
| `pending` | New order waiting for restaurant action | قيد الانتظار |
| `accepted` | Restaurant accepted the order | مقبول |
| `preparing` | Restaurant is preparing the order | قيد التحضير |
| `ready_for_pickup` | Order is ready for pickup / delivery handoff | جاهز للاستلام |
| `picked_up` | Driver/customer picked up the order | تم الاستلام |
| `completed` | Order is completed | مكتمل |
| `cancelled` | Order was rejected/cancelled | ملغي |

## 1. List Restaurant Orders

### Endpoint

```http
GET /api/v1/orders?filter[status]=pending&perPage=20&page=1
```

### Query Parameters

| Parameter | Type | Required | Description |
|---|---:|---:|---|
| `filter[status]` | string | No | Filter orders by status. |
| `filter[orderType]` | string | No | Filter by `delivery`, `pickup`, or `dine_in`. |
| `filter[pickupMode]` | string | No | Filter by `immediate_pickup` or `scheduled_pickup`. |
| `filter[dateFrom]` | date | No | Filter by created date from. |
| `filter[dateTo]` | date | No | Filter by created date to. |
| `filter[createdToday]` | boolean | No | Return only orders created today. |
| `filter[hasDispute]` | boolean | No | Return orders with disputes. |
| `filter[late]` | boolean | No | Return late scheduled pickup orders. |
| `perPage` | integer | No | Pagination size. Default is usually `20`. |
| `page` | integer | No | Page number. |

### Flutter Tab Mapping

| UI Tab | API Query |
|---|---|
| Pending | `GET /api/v1/orders?filter[status]=pending` |
| Accepted | `GET /api/v1/orders?filter[status]=accepted` |
| Preparing | `GET /api/v1/orders?filter[status]=preparing` |
| Ready | `GET /api/v1/orders?filter[status]=ready_for_pickup` |
| Picked Up | `GET /api/v1/orders?filter[status]=picked_up` |
| Completed | `GET /api/v1/orders?filter[status]=completed` |
| Cancelled | `GET /api/v1/orders?filter[status]=cancelled` |

### Response Example

```json
{
  "data": [
    {
      "id": 501,
      "userId": 22,
      "restaurantId": 3,
      "orderNumber": "ORD-X9K2D8LA-4921",
      "status": "pending",
      "statusLabelAr": "قيد الانتظار",
      "orderType": "delivery",
      "pickupMode": "immediate_pickup",
      "pickupScheduledFor": null,
      "subtotal": 50000,
      "discountAmount": 0,
      "taxAmount": 0,
      "serviceFee": 0,
      "totalAmount": 50000,
      "specialInstructions": "Call me when you arrive",
      "restaurant": {
        "id": 3,
        "name": "Pizza House",
        "slug": "pizza-house"
      },
      "orderItems": [
        {
          "id": 9001,
          "orderId": 501,
          "productId": 40,
          "quantity": 2,
          "unitPrice": 25000,
          "totalPrice": 50000,
          "specialInstructions": null,
          "product": {
            "id": 40,
            "name": "Chicken Pizza"
          }
        }
      ],
      "createdAt": "2026-06-24 18:00:00",
      "updatedAt": "2026-06-24 18:00:00"
    }
  ],
  "links": {
    "first": "https://example.com/api/v1/orders?page=1",
    "last": "https://example.com/api/v1/orders?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

## 2. Show Owner-Focused Order Details

Use this endpoint for the restaurant owner order-details page.

### Endpoint

```http
GET /api/v1/restaurant-owner/orders/{orderId}
```

### Response Example

```json
{
  "data": {
    "id": 501,
    "restaurantId": 3,
    "orderNumber": "ORD-X9K2D8LA-4921",
    "status": "pending",
    "customer": {
      "id": 22,
      "name": "Mustafa",
      "phone": "+963900000000"
    },
    "items": [
      {
        "id": 9001,
        "productId": 40,
        "name": "Chicken Pizza",
        "quantity": 2,
        "unitPrice": 25000,
        "totalPrice": 50000,
        "specialInstructions": null
      }
    ],
    "amounts": {
      "subtotal": 50000,
      "discount": 0,
      "tax": 0,
      "serviceFee": 0,
      "total": 50000
    },
    "canEditItems": true,
    "paymentBreakdown": {
      "subtotal": 50000,
      "discount": 0,
      "total": 50000
    }
  }
}
```

## 3. Accept Order

### Endpoint

```http
POST /api/v1/orders/{orderId}/accept
```

### Allowed Current Status

The Flutter app should show this action only when:

```text
status = pending
```

### Request Body

```json
{
  "preparationTimeMinutes": 25,
  "assignedEmployeeId": null,
  "kitchenNotes": "Start with pizza first"
}
```

### Request Fields

| Field | Type | Required | Description |
|---|---:|---:|---|
| `preparationTimeMinutes` | integer | Yes | Estimated preparation time in minutes. |
| `assignedEmployeeId` | integer/null | No | Restaurant employee assigned to prepare/order-manage this order. |
| `kitchenNotes` | string/null | No | Internal notes shown to restaurant staff. |

### Success Response Example

```json
{
  "data": {
    "id": 501,
    "restaurantId": 3,
    "status": "accepted",
    "acceptedAt": "2026-06-24 18:05:00",
    "estimatedPreparationMinutes": 25,
    "kitchenNotes": "Start with pizza first"
  },
  "message": "Order accepted successfully."
}
```

### Flutter Behavior

After success:

1. Update local order status to `accepted`, or refetch order details.
2. Hide the accept/reject buttons.
3. Show the next valid action based on available backend support.

## 4. Reject Order

### Endpoint

```http
POST /api/v1/orders/{orderId}/reject
```

### Allowed Current Status

The Flutter app should show this action only when:

```text
status = pending
```

### Request Body

```json
{
  "reason": "out_of_stock",
  "customerMessage": "Some items are not available now."
}
```

### Request Fields

| Field | Type | Required | Description |
|---|---:|---:|---|
| `reason` | string | Yes | Internal cancellation/rejection reason code. |
| `customerMessage` | string/null | No | Message/reason shown or stored for the customer. |

### Success Response Example

```json
{
  "data": {
    "id": 501,
    "restaurantId": 3,
    "status": "cancelled",
    "cancelledAt": "2026-06-24 18:06:00",
    "cancellationReason": "Some items are not available now."
  },
  "message": "Order rejected successfully."
}
```

### Flutter Behavior

After success:

1. Update local order status to `cancelled`, or refetch order details.
2. Move the order from pending queue to cancelled queue.
3. Do not show preparation actions for cancelled orders.

## 5. Generic Order Update Route

### Endpoint

```http
PATCH /api/v1/orders/{orderId}
PUT /api/v1/orders/{orderId}
```

### Important Warning

Do not use this as the Flutter status-change API unless the backend is refactored.

Reason: the backend request validator currently expects a full order payload, not only `{ "status": "preparing" }`.

The validator accepts `status`, but it also requires fields such as:

- `userId`
- `restaurantId`
- `orderNumber`
- `subtotal`
- `totalAmount`

### Current Status Validation

The generic order update route allows this `status` validation list:

```text
pending, accepted, preparing, ready_for_pickup, picked_up, completed, cancelled
```

## 6. Recommended Backend Improvement

To fully support the restaurant owner app lifecycle, add dedicated action endpoints instead of using the generic full-order update endpoint.

Recommended endpoints:

```http
POST /api/v1/orders/{orderId}/start-preparing
POST /api/v1/orders/{orderId}/ready-for-pickup
POST /api/v1/orders/{orderId}/mark-picked-up
POST /api/v1/orders/{orderId}/complete
```

Or, if a generic status endpoint is preferred:

```http
PATCH /api/v1/orders/{orderId}/status
```

Request:

```json
{
  "status": "preparing"
}
```

Suggested allowed transitions:

| Current Status | Allowed Next Status |
|---|---|
| `pending` | `accepted`, `cancelled` |
| `accepted` | `preparing`, `cancelled` |
| `preparing` | `ready_for_pickup`, `cancelled` |
| `ready_for_pickup` | `picked_up`, `completed` |
| `picked_up` | `completed` |
| `completed` | none |
| `cancelled` | none |

## 7. Flutter Integration Notes

### Do

- Use exact enum strings from this file.
- Use `GET /api/v1/restaurant-owner/orders/{orderId}` for order details.
- Use `POST /api/v1/orders/{orderId}/accept` for accepting pending orders.
- Use `POST /api/v1/orders/{orderId}/reject` for rejecting pending orders.
- Refetch order details after every successful status action.
- Hide invalid buttons based on the current status.

### Do Not

- Do not send `restaurantId` in owner-scoped requests unless the endpoint explicitly requires it.
- Do not treat `restaurantId = null` as a valid restaurant owner order state.
- Do not use the generic full-order update endpoint for one-field status changes from Flutter.
- Do not show accept/reject buttons outside `pending` status.

## 8. Error Handling

### 401 Unauthorized

The token is missing, invalid, or expired.

```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden

The owner is trying to access an order that does not belong to their restaurant.

```json
{
  "message": "You do not have access to this order."
}
```

### 422 Validation Error

Invalid request body.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "preparationTimeMinutes": [
      "The preparation time minutes field is required."
    ]
  }
}
```

## 9. QA Checklist

- [ ] Pending orders load with `GET /api/v1/orders?filter[status]=pending`.
- [ ] Owner order details load with `GET /api/v1/restaurant-owner/orders/{orderId}`.
- [ ] Accept button appears only for `pending` orders.
- [ ] Accept endpoint changes status to `accepted`.
- [ ] Reject button appears only for `pending` orders.
- [ ] Reject endpoint changes status to `cancelled`.
- [ ] Cancelled orders do not show preparation actions.
- [ ] Completed orders do not show any status-change action.
- [ ] Flutter handles all enum values safely.
- [ ] Flutter refetches details after every status action.
- [ ] If preparing/ready/completed buttons are required in UI, backend must first add dedicated endpoints or a status-only endpoint.
