# Supermarket Owner App API Contract — Store-Scoped Orders

## Purpose

This contract documents the supermarket owner app behavior after enforcing one cart per supermarket store and one order per store cart.

New backend invariant:

- every supermarket cart belongs to exactly one store
- every supermarket order is created from exactly one store cart
- `sm_orders.store_id` is always the checked-out cart store
- no mixed-store supermarket order exists

## Flutter scan notes

The scanned `dllni_supermarket_owner_app` currently uses these order endpoints in `lib/features/orders/data/source/orders_remote_data_source.dart`:

- `GET /api/v1/sm-orders`
- `GET /api/v1/sm-orders/{orderId}`
- `POST /api/v1/store-owner/orders/{orderId}/accept`
- `POST /api/v1/store-owner/orders/{orderId}/reject`
- `POST /api/v1/store-owner/orders/{orderId}/courier-handover`

These endpoints remain valid. The main contract change is that each received order belongs to exactly one store and all items belong to that same store.

## Base URL and headers

```http
Authorization: Bearer {storeOwnerToken}
Accept: application/json
Content-Type: application/json
```

All endpoints are relative to:

```http
/api/v1
```

The backend uses `InjectStoreIdFromOwnerContext` for owner-scoped supermarket APIs. The app must not send `storeId` to switch context.

---

# 1. Orders list

```http
GET /api/v1/sm-orders?filter[status]=pending&perPage=20&page=1
```

Response:

```json
{
  "data": [
    {
      "id": 801,
      "customerId": 22,
      "storeId": 7,
      "store": {
        "id": 7,
        "name": "Fresh Market"
      },
      "couponId": null,
      "orderNumber": "SM-XY91KJ22-4832",
      "status": "pending",
      "pickupMode": "scheduled_pickup",
      "pickupScheduledFor": "2026-06-24 20:30:00",
      "subtotal": "36000.00",
      "discountAmount": "0.00",
      "serviceFee": "0.00",
      "totalAmount": "36000.00",
      "specialInstructions": "Call before arrival",
      "items": [
        {
          "id": 9101,
          "productId": 501,
          "quantity": 3,
          "unitPrice": "12000.00",
          "totalPrice": "36000.00",
          "productName": "Milk 1L"
        }
      ],
      "createdAt": "2026-06-24 18:00:00",
      "updatedAt": "2026-06-24 18:00:00"
    }
  ],
  "links": {
    "first": "https://example.com/api/v1/sm-orders?page=1",
    "last": "https://example.com/api/v1/sm-orders?page=1",
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

UI rule: no mixed-store grouping is needed. One order card equals one store order.

---

# 2. Order details

```http
GET /api/v1/sm-orders/{orderId}
```

Response:

```json
{
  "data": {
    "id": 801,
    "customerId": 22,
    "customer": {
      "id": 22,
      "name": "Mustafa",
      "phone": "+963900000000"
    },
    "storeId": 7,
    "store": {
      "id": 7,
      "name": "Fresh Market"
    },
    "orderNumber": "SM-XY91KJ22-4832",
    "status": "pending",
    "pickupMode": "scheduled_pickup",
    "pickupScheduledFor": "2026-06-24 20:30:00",
    "subtotal": "36000.00",
    "discountAmount": "0.00",
    "serviceFee": "0.00",
    "totalAmount": "36000.00",
    "specialInstructions": "Call before arrival",
    "items": [
      {
        "id": 9101,
        "productId": 501,
        "quantity": 3,
        "unitPrice": "12000.00",
        "totalPrice": "36000.00",
        "productName": "Milk 1L",
        "product": {
          "id": 501,
          "name": "Milk 1L"
        }
      }
    ],
    "statusLogs": [
      {
        "fromStatus": null,
        "toStatus": "pending",
        "notes": "Order placed by customer.",
        "createdAt": "2026-06-24 18:00:00"
      }
    ],
    "createdAt": "2026-06-24 18:00:00",
    "updatedAt": "2026-06-24 18:00:00"
  }
}
```

UI rule: assume all items belong to the authenticated owner store. If a product is unavailable after order creation, show its availability/inventory status in the item row if the API includes it.

---

# 3. Accept order

```http
POST /api/v1/store-owner/orders/{orderId}/accept
```

Request:

```json
{
  "preparationTimeMinutes": 20,
  "note": "Preparing now"
}
```

Response:

```json
{
  "data": {
    "id": 801,
    "storeId": 7,
    "status": "accepted",
    "orderNumber": "SM-XY91KJ22-4832",
    "acceptedAt": "2026-06-24 18:05:00"
  }
}
```

UI behavior:

- Show accept action only for `pending` orders.
- After success, update the card status or refetch the list.

---

# 4. Reject order

```http
POST /api/v1/store-owner/orders/{orderId}/reject
```

Request:

```json
{
  "reason": "out_of_stock",
  "message": "Some items are not available."
}
```

Response:

```json
{
  "data": {
    "id": 801,
    "storeId": 7,
    "status": "cancelled",
    "cancelledAt": "2026-06-24 18:06:00",
    "cancellationReason": "Some items are not available."
  }
}
```

UI behavior:

- Require a reason before submit.
- Show reject action only for `pending` orders.

---

# 5. Courier handover

```http
POST /api/v1/store-owner/orders/{orderId}/courier-handover
```

Request:

```json
{
  "note": "Handed to courier"
}
```

Response:

```json
{
  "data": {
    "id": 801,
    "storeId": 7,
    "status": "picked_up",
    "pickedUpAt": "2026-06-24 18:40:00"
  }
}
```

UI behavior:

- Show courier handover action only after the order is prepared/ready according to the current status rules.
- After success, refetch details or update status locally.

---

# 6. Required Flutter updates

No endpoint path changes are required for the current scanned supermarket owner app order data source.

Required UI/data assumptions:

1. Do not render mixed-store groups in order details.
2. Treat `storeId` as required and always equal to the authenticated store context.
3. Order list filters remain status-based.
4. Details screen should display one store name and one item list.
5. Accept/reject/courier handover buttons remain status-driven.
6. Inventory warnings can be shown per item, but item availability does not change the cart/order grouping rule.

---

# 7. QA scenarios

1. Customer creates carts from Store A and Store B.
2. Customer checks out only Store A cart.
3. Store A owner sees the order in `GET /api/v1/sm-orders`.
4. Store B owner does not see Store A order.
5. Store A owner accepts the order.
6. Customer checks out Store B cart later and creates a separate order.
7. No supermarket owner API response contains items from two stores in one order.
