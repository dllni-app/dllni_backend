# Restaurant Owner App API Contract — Merchant-Scoped Orders

## Purpose

This contract documents the restaurant owner app impact after removing mixed-restaurant carts and mixed-restaurant orders.

New backend invariant:

- every restaurant cart belongs to exactly one restaurant
- every restaurant order is created from exactly one restaurant cart
- `orders.restaurant_id` must be present for user-created orders
- the owner app should never receive a mixed-restaurant order

## Flutter repository note

No `restaurant_owner_app` repository was available in the connected repository list during this scan. This contract is based on the backend restaurant owner routes and existing backend owner contract. Use it to update the Flutter restaurant owner app once the repo is available.

## Base URL and headers

```http
Authorization: Bearer {restaurantOwnerToken}
Accept: application/json
Content-Type: application/json
```

All endpoints are relative to:

```http
/api/v1
```

The backend infers the current restaurant from the authenticated restaurant seller/owner. The owner app must not send `restaurantId` for owner-scoped requests.

---

# 1. What changed for owner app

## Before

The user app could create one mixed restaurant order. In that case `restaurantId` could be `null`, and the order contained items from multiple restaurants.

## Now

That flow is removed. User checkout is cart-specific:

```http
POST /api/v1/user/restaurants/carts/{cartId}/orders
```

The created order always belongs to one restaurant. The restaurant owner app should receive only orders where:

```json
{
  "restaurantId": 3
}
```

No owner screen should handle `restaurantId = null` as a valid order state.

---

# 2. Orders list

The existing restaurant owner list endpoint remains:

```http
GET /api/v1/orders?filter[status]=pending&perPage=20&page=1
```

Common filters:

| Filter | Example | Notes |
|---|---|---|
| `filter[status]` | `pending` | `pending`, `accepted`, `preparing`, `ready_for_pickup`, `picked_up`, `completed`, `cancelled` |
| `perPage` | `20` | pagination size |
| `page` | `1` | page number |

Response example:

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

## UI mapping

- Pending tab: `GET /api/v1/orders?filter[status]=pending`
- Accepted tab: `GET /api/v1/orders?filter[status]=accepted`
- Preparing tab: `GET /api/v1/orders?filter[status]=preparing`
- Ready tab: `GET /api/v1/orders?filter[status]=ready_for_pickup`
- Completed tab: `GET /api/v1/orders?filter[status]=completed`
- Cancelled tab: `GET /api/v1/orders?filter[status]=cancelled`

---

# 3. Owner-focused order details

```http
GET /api/v1/restaurant-owner/orders/{orderId}
```

Use this for the order details screen because it is owner-focused and includes editing permissions and payment/total context.

Response shape should include at least:

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

UI rule: do not show cross-restaurant grouping. The details screen is one restaurant only.

---

# 4. Accept order

```http
POST /api/v1/orders/{orderId}/accept
```

Request:

```json
{
  "preparationTimeMinutes": 25,
  "assignedEmployeeId": null,
  "kitchenNotes": "Start with pizza first"
}
```

Response:

```json
{
  "data": {
    "id": 501,
    "restaurantId": 3,
    "status": "accepted",
    "acceptedAt": "2026-06-24 18:05:00",
    "estimatedPreparationMinutes": 25,
    "kitchenNotes": "Start with pizza first"
  }
}
```

UI behavior:

- Hide accept button unless status is `pending`.
- After success, update the order card to `accepted` or refetch the list.

---

# 5. Reject order

```http
POST /api/v1/orders/{orderId}/reject
```

Request:

```json
{
  "reason": "out_of_stock",
  "customerMessage": "Some items are not available now."
}
```

Response:

```json
{
  "data": {
    "id": 501,
    "restaurantId": 3,
    "status": "cancelled",
    "cancelledAt": "2026-06-24 18:06:00",
    "cancellationReason": "Some items are not available now."
  }
}
```

UI behavior:

- Require a reject reason.
- Hide reject button unless status is `pending`.

---

# 6. Edit order items

These endpoints remain owner-scoped and now always operate on one restaurant order.

## Add item

```http
POST /api/v1/restaurant-owner/orders/{orderId}/items
```

Request:

```json
{
  "productId": 44,
  "quantity": 1,
  "unitPrice": 22000,
  "specialInstructions": "Extra sauce"
}
```

## Update item

```http
PATCH /api/v1/restaurant-owner/orders/{orderId}/items/{itemId}
```

Request:

```json
{
  "quantity": 3,
  "unitPrice": 25000,
  "specialInstructions": "No onions"
}
```

## Delete item

```http
DELETE /api/v1/restaurant-owner/orders/{orderId}/items/{itemId}
```

UI rule: product picker must only show products from the authenticated restaurant.

---

# 7. Required Flutter updates

1. Remove any UI handling for `restaurantId = null` orders.
2. Remove any mixed-merchant group UI in the owner order details screen.
3. Order list cards can assume one restaurant context.
4. Keep order status actions unchanged: accept, reject, prepare, ready, complete where currently supported.
5. When opening order details, use `GET /api/v1/restaurant-owner/orders/{orderId}`.
6. When editing items, validate that only current restaurant products can be selected.

---

# 8. QA scenarios

1. User creates two carts from two restaurants.
2. User checks out only Restaurant A cart.
3. Restaurant A owner sees the order.
4. Restaurant B owner does not see the order.
5. Restaurant A owner accepts the order.
6. Restaurant A owner edits items and totals stay restaurant-scoped.
7. No owner app screen receives or renders an order with `restaurantId = null`.
