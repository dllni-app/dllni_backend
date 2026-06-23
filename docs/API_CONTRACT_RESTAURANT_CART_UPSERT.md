# Restaurant Cart Upsert API Contract

## Scope

This contract documents the restaurant cart behavior needed by the Flutter user app after the cart duplicate fix.

Base authenticated prefix:

```text
/api/v1/user
```

All cart write endpoints require `Authorization: Bearer <token>`.

## 1. Add or Update Cart Item

```http
POST /api/v1/user/restaurants/cart/items
```

### Purpose

Adds a restaurant product to the current user's cart. If the same cart line already exists, the backend updates that existing line instead of creating a duplicate.

### Matching Rule

A cart item is considered the same line when all these values match after normalization:

| Field | Rule |
|---|---|
| `productId` | Same product. |
| `modifierIds` | Same sorted unique modifier IDs. Different order still matches. |
| `substituteProductId` | Same substitute product or both `null`. |
| `specialInstructions` / `note` | Same normalized text. Empty string is treated as `null`. |

Same product with different modifiers or different notes remains a separate cart line.

### Request Body

```json
{
  "productId": 2,
  "quantity": 3,
  "modifierIds": [10, 11],
  "substituteProductId": null,
  "specialInstructions": "No onions"
}
```

### Validation

| Field | Type | Required | Rules |
|---|---:|---:|---|
| `productId` | int | Yes | Must exist in `products`. |
| `quantity` | int | Yes | Min `1`, max `50`. |
| `modifierIds` | int[] | No | Max 30. Every ID must exist and must be allowed for the selected product. |
| `substituteProductId` | int/null | No | Must exist in `products` when sent. |
| `specialInstructions` | string/null | No | Max 1000 characters. |
| `note` | string/null | No | Legacy alias for `specialInstructions`. |

### Response: New Line Created

Status: `201 Created`

```json
{
  "message": "Item added to cart.",
  "cartId": 1,
  "itemId": 57,
  "quantity": 3,
  "operation": "created",
  "cartProductsCount": 8
}
```

### Response: Existing Line Updated

Status: `200 OK`

```json
{
  "message": "Item updated in cart.",
  "cartId": 1,
  "itemId": 57,
  "quantity": 3,
  "operation": "updated",
  "cartProductsCount": 8
}
```

### Flutter Notes

- Treat `quantity` as the final quantity of the matching cart line.
- Use `operation` only for UX text if needed.
- Use `cartProductsCount` to refresh the cart badge without an extra request, or continue using the existing count endpoint.
- Existing parsing of `message`, `cartId`, and `itemId` remains backward compatible.

## 2. Update Cart Item Quantity / Options

```http
PATCH /api/v1/user/restaurants/cart/items/{itemId}
```

### Purpose

Updates an existing cart item.

### Request Body: Quantity Only

```json
{
  "quantity": 4
}
```

When only `quantity` is sent, backend keeps the existing modifiers, substitute product, and note.

### Request Body: Update Options

```json
{
  "quantity": 4,
  "modifierIds": [10],
  "substituteProductId": null,
  "specialInstructions": "Extra spicy"
}
```

When `modifierIds`, `substituteProductId`, or `specialInstructions` / `note` are sent, only the sent fields are changed.

### Response

Status: `200 OK`

```json
{
  "data": {
    "id": 1,
    "merchant": {
      "id": 5,
      "name": "Restaurant Name",
      "primaryImageUrl": null,
      "bannerImageUrl": null
    },
    "items": [
      {
        "id": 57,
        "productId": 2,
        "name": "Product Name",
        "quantity": 4,
        "unitPrice": 32,
        "totalPrice": 128,
        "modifierIds": [10],
        "substituteProductId": null,
        "note": "Extra spicy"
      }
    ],
    "merchantGroups": [],
    "productsCount": 4,
    "amounts": {
      "subtotal": 128,
      "total": 128
    }
  }
}
```

## 3. Product Details Cart Quantity

```http
GET /api/v1/user/products/{productId}
```

### Purpose

Returns product details. When the user is authenticated, the product object includes the current quantity of this product in the user's cart.

### Response Addition

```json
{
  "product": {
    "id": 2,
    "name": "Product Name",
    "price": 30,
    "isFavorite": false,
    "cartQuantity": 3
  },
  "shareUrl": "...",
  "modifierGroups": []
}
```

### Rules

| Case | `cartQuantity` |
|---|---:|
| Product is not in cart | `0` |
| Product exists once in cart | The line quantity. |
| Product exists in multiple cart lines with different modifiers/notes | Sum of all quantities for the same product. |
| Guest / unauthenticated request | `0` |

### Flutter Details Page Behavior

- If `cartQuantity > 0`, initialize the quantity selector with `cartQuantity`.
- If `cartQuantity == 0`, initialize the quantity selector with `1`.
- Button label suggestion:
  - `cartQuantity > 0`: `تحديث السلة`
  - `cartQuantity == 0`: `إضافة إلى السلة`

## 4. Cart Products Count

```http
GET /api/v1/user/restaurants/cart/products-count
```

Response remains unchanged:

```json
{
  "productsCount": 8
}
```

`productsCount` is the sum of item quantities, not the number of cart rows.

## Error Responses

Validation errors return `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "modifierIds": [
      "Some modifiers are not allowed for this product."
    ]
  }
}
```

Unauthorized requests return `401` for protected cart endpoints.
