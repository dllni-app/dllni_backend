# API Contract — User Supermarket Multi-Merchant Cart & Checkout

Base path: `/api/v1/user`  
Auth: `auth:sanctum`

## Business Rules

- A user has exactly one active supermarket cart.
- A supermarket cart can contain products from multiple stores/merchants.
- Adding a product from a different store must not create a second cart.
- Adding the same product again must merge into the existing cart item and increase `quantity`.
- Multi-merchant checkout is allowed.
- Checkout creates one checkout batch and one `sm_order` per merchant/store.
- Every generated `sm_order` contains only the items that belong to that order's store.
- The cart is deleted only after all store orders are created successfully.

## Show Cart

`GET /supermarket/cart`

```json
{
  "data": {
    "id": 44,
    "merchant": null,
    "items": [
      {
        "id": 1001,
        "productId": 15,
        "merchantId": 3,
        "merchantName": "Store A",
        "name": "Rice",
        "quantity": 2,
        "unitPrice": 12000,
        "totalPrice": 24000,
        "isAvailableInStock": true,
        "availableStock": 20
      }
    ],
    "merchantGroups": [
      {
        "merchant": { "id": 3, "name": "Store A" },
        "items": [],
        "amounts": { "subtotal": 24000, "total": 24000 }
      }
    ],
    "isMultiMerchant": true,
    "checkout": {
      "canPlaceOrder": true,
      "blockedReason": null,
      "message": null
    },
    "amounts": { "subtotal": 31000, "total": 31000 }
  }
}
```

Flutter notes:

- Use `merchantGroups` for grouped display by store.
- `items` remains as a flat legacy list.
- Do not block checkout when `isMultiMerchant` is true.
- Only block checkout when `checkout.canPlaceOrder` is false.

## Add Cart Item

`POST /supermarket/cart/items`

```json
{
  "productId": 15,
  "quantity": 2
}
```

Behavior:

- If product is new to the cart, a new cart line is created.
- If product already exists in the cart, the existing cart line quantity is incremented.
- If product belongs to another store, it is still added to the same user cart.
- If requested quantity exceeds stock, backend returns `422`.

## Update Cart Item

`PATCH /supermarket/cart/items/{itemId}`

```json
{
  "quantity": 4
}
```

Behavior:

- Sets the line quantity to the provided value.
- Validates product availability and stock.
- Returns full cart payload.

## Checkout Preview

`POST /supermarket/checkout/preview`

```json
{
  "fulfillmentType": "delivery",
  "receiveMode": "immediate",
  "scheduledAt": null,
  "addressId": 22,
  "merchantCoupons": [
    { "merchantId": 3, "couponCode": "A10" },
    { "merchantId": 8, "couponCode": "B10" }
  ],
  "note": "Please call before arrival"
}
```

Response includes `merchantGroups[]`, grouped coupon status, grouped amounts, and grand `amounts`.

## Place Order From Cart

`POST /supermarket/orders`

```json
{
  "fulfillmentType": "delivery",
  "receiveMode": "immediate",
  "scheduledAt": null,
  "addressId": 22,
  "merchantCoupons": [
    { "merchantId": 3, "couponCode": "A10" },
    { "merchantId": 8, "couponCode": "B10" }
  ],
  "note": "Please call before arrival"
}
```

### Response

```json
{
  "data": {
    "id": 501,
    "section": "supermarket",
    "checkoutBatchNumber": "SMC-X8K2P9AA-1842",
    "isMultiMerchant": true,
    "createdOrdersCount": 2,
    "orders": [
      {
        "id": 501,
        "section": "supermarket",
        "checkoutBatchNumber": "SMC-X8K2P9AA-1842",
        "checkoutOrdersCount": 2,
        "orderNumber": "SM-A1B2C3D4-1111",
        "status": "pending",
        "statusLabel": "Pending",
        "merchant": { "id": 3, "name": "Store A" },
        "fulfillment": {
          "type": "delivery",
          "receiveMode": "immediate",
          "scheduledAt": null
        },
        "amounts": {
          "subtotal": 24000,
          "discount": 2400,
          "serviceFee": 0,
          "tax": 0,
          "total": 21600
        },
        "items": [
          {
            "id": 9001,
            "productId": 15,
            "name": "Rice",
            "quantity": 2,
            "unitPrice": 12000,
            "totalPrice": 24000,
            "note": null
          }
        ]
      }
    ],
    "amounts": {
      "subtotal": 31000,
      "discount": 2400,
      "serviceFee": 0,
      "tax": 0,
      "total": 28600
    }
  }
}
```

Backward compatibility:

- The first generated order is also merged at the `data` root (`id`, `orderNumber`, `status`, etc.) so older Flutter builds can still read the first order after checkout.
- New Flutter builds must use `data.orders[]` as the source of truth.
