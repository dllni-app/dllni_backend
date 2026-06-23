# Restaurant Cart Upsert API Contract

## Scope

This contract documents the backend behavior for restaurant cart item add/update and product-details cart quantity. It is intended for the Flutter user app.

Base path:

```text
/api/v1/user
```

Authentication:

- Cart write/read endpoints require `Authorization: Bearer <token>`.
- Product details can be opened by guests, but `cartQuantity` is only meaningful when the request includes a valid Sanctum token.

## Business Rules

1. A cart line is considered the same line when these values match after normalization:
   - `productId`
   - sorted unique `modifierIds`
   - `substituteProductId`
   - normalized `specialInstructions` / `note`
2. Adding the same line again does not create a duplicate row.
3. Adding the same product with different modifiers, substitute, or note creates a separate line.
4. `cartQuantity` is an integer quantity, not a boolean.
5. `cartQuantity = 0` means the product is not currently in the authenticated user's restaurant cart.
6. PATCH quantity updates preserve existing modifiers, substitute, and note unless those fields are explicitly sent.

## 1. Add or Upsert Restaurant Cart Item

### Endpoint

```http
POST /api/v1/user/restaurants/cart/items
```

### Request Body

```json
{
  "productId": 2,
  "quantity": 1,
  "quantityMode": "increment",
  "modifierIds": [],
  "substituteProductId": null,
  "specialInstructions": ""
}
```

### Fields

| Field | Type | Required | Rules | Notes |
|---|---:|---:|---|---|
| `productId` | int | Yes | existing product id | Product must be linked to a restaurant. |
| `quantity` | int | Yes | `1..50` | Used according to `quantityMode`. |
| `quantityMode` | string | No | `increment` or `set` | Defaults to `increment` for backward compatibility. |
| `modifierIds` | int[] | No | max 30, existing modifier ids | Must be allowed for the product. Order does not matter. |
| `substituteProductId` | int/null | No | existing product id or null | Part of cart-line identity. |
| `specialInstructions` | string/null | No | max 1000 | Preferred Flutter field. Empty string is normalized to null. |
| `note` | string/null | No | max 1000 | Backward-compatible alias. `specialInstructions` wins when both are sent. |

### Quantity Modes

#### `increment` default

Use this for quick-add buttons or when the user is adding more units.

If the same line already exists:

```text
new quantity = current cart line quantity + request quantity
```

#### `set`

Use this from product details when the backend already returned `product.cartQuantity` and the quantity picker represents the final desired quantity.

If the same line already exists:

```text
new quantity = request quantity
```

### Created Response

Status: `201 Created`

```json
{
  "message": "Item added to cart.",
  "cartId": 1,
  "itemId": 57,
  "quantity": 1,
  "cartProductsCount": 1,
  "operation": "created"
}
```

### Updated Response

Status: `200 OK`

```json
{
  "message": "Item updated in cart.",
  "cartId": 1,
  "itemId": 57,
  "quantity": 3,
  "cartProductsCount": 3,
  "operation": "updated"
}
```

### Flutter Guidance

Product details flow:

1. Read `product.cartQuantity` from product details.
2. If `cartQuantity > 0`, initialize the quantity picker with that value.
3. When submitting from details, send `quantityMode: "set"`.
4. If `cartQuantity == 0`, initialize the picker with `1`. Sending `set` is still safe because the line will be created.

Quick-add flow:

- Send no `quantityMode`, or send `quantityMode: "increment"`.

## 2. Update Restaurant Cart Item Quantity

### Endpoint

```http
PATCH /api/v1/user/restaurants/cart/items/{itemId}
```

### Request Body: quantity only

```json
{
  "quantity": 7
}
```

This updates only the quantity. Existing modifiers, substitute product, and note remain unchanged.

### Optional Full Update Body

```json
{
  "quantity": 7,
  "modifierIds": [12, 15],
  "substituteProductId": null,
  "specialInstructions": "No onion"
}
```

Only explicitly sent optional fields are replaced.

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
        "primaryImageUrl": null,
        "images": [],
        "quantity": 7,
        "unitPrice": 32,
        "totalPrice": 224,
        "modifierIds": [12],
        "substituteProductId": null,
        "note": null
      }
    ],
    "merchantGroups": [],
    "amounts": {
      "subtotal": 224,
      "total": 224
    }
  }
}
```

## 3. Product Details Cart Quantity

### Endpoint

```http
GET /api/v1/user/products/{productId}
```

### Authenticated Response

```json
{
  "product": {
    "id": 2,
    "restaurantId": 5,
    "categoryId": 8,
    "name": "Product Name",
    "description": "...",
    "price": 30,
    "discountedPrice": null,
    "isFavorite": false,
    "cartQuantity": 3,
    "isAvailable": true,
    "isAvailableNow": true,
    "availabilityMode": "always",
    "unavailableUntil": null,
    "availabilityNote": null,
    "stockQuantity": null,
    "lowStockThreshold": null,
    "preparationTime": null,
    "isFeatured": false,
    "primaryImage": "https://...",
    "images": [],
    "createdAt": "2026-06-23 20:00:00",
    "updatedAt": "2026-06-23 20:00:00"
  },
  "shareUrl": "https://...",
  "modifierGroups": []
}
```

### Guest Response

For guest/no-token requests:

```json
{
  "product": {
    "id": 2,
    "cartQuantity": 0
  }
}
```

## 4. Validation Errors

Status: `422 Unprocessable Entity`

Example:

```json
{
  "message": "The selected product id is invalid.",
  "errors": {
    "productId": [
      "The selected product id is invalid."
    ]
  }
}
```

Common validation cases:

- Invalid product id.
- Quantity less than 1 or greater than 50.
- `quantityMode` is not `increment` or `set`.
- Modifier id does not exist.
- Modifier id exists but is not allowed for the selected product.

## 5. Flutter Model Changes

### AddRestaurantCartItemModel

Add optional fields while keeping old fields:

```dart
class AddRestaurantCartItemModel {
  final String? message;
  final int? cartId;
  final int? itemId;
  final int? quantity;
  final int? cartProductsCount;
  final String? operation; // created | updated
}
```

### AddRestaurantCartItemParams

Add optional field:

```dart
final String quantityMode; // increment | set
```

Default recommendation:

```dart
quantityMode = 'increment'
```

For product details submit:

```dart
quantityMode = 'set'
```

### RestaurantProductDetailsProduct

Add:

```dart
final int cartQuantity;
```

Parse from:

```dart
json['cartQuantity'] ?? json['cart_quantity']
```

## 6. QA Scenarios

1. Open product details for a product not in cart: `cartQuantity = 0`.
2. Add product with quantity 1: line created, `cartProductsCount = 1`.
3. Add same product again with default `increment`: one line remains, quantity becomes 2.
4. Open product details again: `cartQuantity = 2`.
5. Submit product details with quantity 5 and `quantityMode = set`: one line remains, quantity becomes 5.
6. PATCH cart item quantity only: modifiers remain unchanged.
7. Add same product with a different modifier: a second cart line is created.
