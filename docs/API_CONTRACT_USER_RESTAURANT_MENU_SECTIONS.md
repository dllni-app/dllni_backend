# API Contract - User Restaurant Menu Sections

**Audience:** Mobile / frontend developers
**Base URL:** `https://dllni.mustafafares.com`
**API prefix:** `/api/v1/user`
**Auth:** Public endpoint. If a Sanctum token is provided, `isFavorite` is resolved for the authenticated user.

---

## 1. Scope

This contract documents the user restaurant sectioned menu endpoint:

- `GET /api/v1/user/restaurants/{restaurant}/menu-sections`

The endpoint is designed for restaurant menu tabs/sections screens (like Meals, Sandwiches, Appetizers, Drinks), where each section returns a limited list of product cards.

---

## 2. Request

### 2.1 Path parameters

| Param | Type | Required | Notes |
| ----- | ---- | -------- | ----- |
| restaurant | integer | yes | Restaurant id (`restaurants.id`) |

### 2.2 Query parameters

| Param | Type | Required | Notes |
| ----- | ---- | -------- | ----- |
| itemsPerSection | integer | no | Number of items returned inside each section. Default `10`, min `1`, max `30`. |

### 2.3 Example request

```http
GET https://dllni.mustafafares.com/api/v1/user/restaurants/12/menu-sections?itemsPerSection=3
Accept: application/json
Authorization: Bearer {token}
```

`Authorization` header is optional.

---

## 3. Success response

**Status:** `200 OK`

### 3.1 Response schema

| Field | Type | Notes |
| ----- | ---- | ----- |
| restaurantId | integer | Restaurant id from path param |
| itemsPerSection | integer | Effective item limit per section |
| sections | array | Section list sorted by category `sort_order` |
| sections[].id | integer | Category id |
| sections[].name | string | Category name |
| sections[].sortOrder | integer | Category sort order |
| sections[].totalProducts | integer | Total available products in this category (before item limit) |
| sections[].items | array | Limited list of product cards |
| sections[].items[].id | integer | Product id |
| sections[].items[].name | string | Product name |
| sections[].items[].description | string \| null | Product description |
| sections[].items[].sizeLabel | string \| null | Reserved for size text. Currently returns `null`. |
| sections[].items[].displayPrice | number \| null | Price shown to user (`discounted_price` when valid, otherwise `price`) |
| sections[].items[].originalPrice | number \| null | Original price when a valid discount exists, else `null` |
| sections[].items[].currency | string | Currency from `config('app.currency')`, fallback `IQD` |
| sections[].items[].primaryImageUrl | string \| null | Product primary image URL |
| sections[].items[].isFeatured | boolean | Product featured flag |
| sections[].items[].isFavorite | boolean | `true` only when user is authenticated and product is favorited |

### 3.2 Example response

```json
{
  "restaurantId": 12,
  "itemsPerSection": 3,
  "sections": [
    {
      "id": 41,
      "name": "وجبات",
      "sortOrder": 1,
      "totalProducts": 8,
      "items": [
        {
          "id": 501,
          "name": "بيتزا مارغريتا",
          "description": "جبنة موزاريلا وصلصة طماطم طازجة",
          "sizeLabel": null,
          "displayPrice": 450.0,
          "originalPrice": null,
          "currency": "IQD",
          "primaryImageUrl": "https://cdn.example.com/products/501-primary.jpg",
          "isFeatured": true,
          "isFavorite": false
        },
        {
          "id": 502,
          "name": "بيتزا بيبروني",
          "description": "شرائح بيبروني وجبنة",
          "sizeLabel": null,
          "displayPrice": 650.0,
          "originalPrice": 750.0,
          "currency": "IQD",
          "primaryImageUrl": "https://cdn.example.com/products/502-primary.jpg",
          "isFeatured": false,
          "isFavorite": true
        },
        {
          "id": 503,
          "name": "بيتزا خضار",
          "description": "فلفل وفطر وزيتون",
          "sizeLabel": null,
          "displayPrice": 500.0,
          "originalPrice": null,
          "currency": "IQD",
          "primaryImageUrl": null,
          "isFeatured": false,
          "isFavorite": false
        }
      ]
    },
    {
      "id": 44,
      "name": "مشاريب",
      "sortOrder": 4,
      "totalProducts": 2,
      "items": [
        {
          "id": 610,
          "name": "بيبسي",
          "description": null,
          "sizeLabel": null,
          "displayPrice": 100.0,
          "originalPrice": null,
          "currency": "IQD",
          "primaryImageUrl": "https://cdn.example.com/products/610-primary.jpg",
          "isFeatured": false,
          "isFavorite": false
        },
        {
          "id": 611,
          "name": "عصير برتقال",
          "description": "طازج",
          "sizeLabel": null,
          "displayPrice": 200.0,
          "originalPrice": null,
          "currency": "IQD",
          "primaryImageUrl": null,
          "isFeatured": false,
          "isFavorite": false
        }
      ]
    }
  ]
}
```

---

## 4. Error responses

### 4.1 Validation error

**Status:** `422 Unprocessable Entity`

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "itemsPerSection": [
      "The items per section field must be at least 1."
    ]
  }
}
```

### 4.2 Restaurant not found / inactive

**Status:** `404 Not Found`

```json
{
  "message": "No query results for model [Modules\\Resturants\\Models\\Restaurant] 12"
}
```

### 4.3 Unauthorized token

**Status:** `401 Unauthorized`

Returned only when an invalid bearer token is sent.

---

## 5. Integration notes

- The endpoint excludes unavailable products (`is_available = false`).
- Categories with zero available products are excluded from `sections`.
- `totalProducts` helps frontend decide whether to show "View all" for each section.
- If you need full category pagination, use this endpoint for UI sections and call category/product listing endpoints for deep navigation.
