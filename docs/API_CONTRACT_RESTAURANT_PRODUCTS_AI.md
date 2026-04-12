# API Contract - Restaurant Products AI

**Audience:** Frontend / mobile developers (Restaurant Seller app)
**Base URL:** `https://dllni.mustafafares.com`
**API prefix:** `/api/v1`
**Auth:** Laravel Sanctum (`Authorization: Bearer {token}`)
**Content type:**
- `multipart/form-data` for image extraction endpoints
- `application/json` for image generation endpoint

---

## 1. Scope

This contract documents Restaurant Product AI endpoints exposed under:

- `POST /api/v1/products/ai/extract-from-image`
- `POST /api/v1/products/ai/extract-from-menu`
- `POST /api/v1/products/ai/generate-image`

Route source:
- `Modules/Resturants/routes/api.php`

Controller:
- `App\Http\Controllers\API\ProductAiController`

---

## 2. Authentication and access

All endpoints in this document require:

- Valid Sanctum bearer token
- Header: `Authorization: Bearer {token}`

If token is missing or invalid:

- HTTP `401 Unauthorized`

---

## 3. Shared request rules

### 3.1 Locale

Optional field on extraction endpoints:

- `locale` (string)
- Allowed values: `ar`, `en`
- If omitted, backend lets AI infer dominant language from image context

### 3.2 Error format (validation)

Validation failures follow Laravel standard `422` shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "fieldName": [
      "Validation message"
    ]
  }
}
```

---

## 4. Endpoint: Extract single product from image

### 4.1 Summary

Extracts a suggested product title and description from one product image.

- Method: `POST`
- Path: `/api/v1/products/ai/extract-from-image`
- Content-Type: `multipart/form-data`

### 4.2 Request body

| Field | Type | Required | Constraints | Description |
| ----- | ---- | -------- | ----------- | ----------- |
| image | file | yes | Must be image, max 8192 KB | Product image file |
| locale | string | no | `ar` or `en` | Force output language preference |

### 4.3 Example request (cURL)

```bash
curl -X POST "https://dllni.mustafafares.com/api/v1/products/ai/extract-from-image" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -F "image=@/path/to/product.jpg" \
  -F "locale=en"
```

### 4.4 Success response (`200 OK`)

```json
{
  "data": {
    "title": "Classic Chicken Burger",
    "description": "Juicy grilled chicken burger with lettuce and house sauce."
  }
}
```

### 4.5 Fallback behavior

If AI processing fails internally, endpoint still returns `200` with null fields:

```json
{
  "data": {
    "title": null,
    "description": null
  }
}
```

Client guidance:

- Treat null title as extraction failure
- Show manual input UI fallback

### 4.6 Possible error responses

- `401 Unauthorized` (missing/invalid token)
- `422 Unprocessable Entity` (invalid file, file too large, invalid locale)

Example 422 (invalid locale):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "locale": [
      "The selected locale is invalid."
    ]
  }
}
```

---

## 5. Endpoint: Extract multiple menu items from image

### 5.1 Summary

Extracts a list of menu/product items from a menu photo.

- Method: `POST`
- Path: `/api/v1/products/ai/extract-from-menu`
- Content-Type: `multipart/form-data`

### 5.2 Request body

| Field | Type | Required | Constraints | Description |
| ----- | ---- | -------- | ----------- | ----------- |
| image | file | yes | Must be image, max 12288 KB | Menu image file |
| locale | string | no | `ar` or `en` | Force output language preference |

### 5.3 Example request (cURL)

```bash
curl -X POST "https://dllni.mustafafares.com/api/v1/products/ai/extract-from-menu" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -F "image=@/path/to/menu.png" \
  -F "locale=ar"
```

### 5.4 Success response (`200 OK`)

```json
{
  "data": {
    "items": [
      {
        "title": "Margherita Pizza",
        "description": "Tomato sauce, mozzarella, and fresh basil."
      },
      {
        "title": "Caesar Salad",
        "description": "Romaine lettuce with parmesan and Caesar dressing."
      }
    ]
  }
}
```

### 5.5 Fallback behavior

If AI parsing fails, endpoint returns empty list with `200`:

```json
{
  "data": {
    "items": []
  }
}
```

Client guidance:

- If `items` is empty, allow manual menu entry/import

### 5.6 Possible error responses

- `401 Unauthorized`
- `422 Unprocessable Entity`

Example 422 (missing image):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "image": [
      "The image field is required."
    ]
  }
}
```

---

## 6. Endpoint: Generate product image from text

### 6.1 Summary

Generates a square catalog-style product image as a Base64 string.

- Method: `POST`
- Path: `/api/v1/products/ai/generate-image`
- Content-Type: `application/json`

### 6.2 Request body

| Field | Type | Required | Constraints | Description |
| ----- | ---- | -------- | ----------- | ----------- |
| title | string | yes | max 255 | Product title used in prompt |
| description | string or null | no | max 2000 | Optional detail for prompt enrichment |

### 6.3 Example request (cURL)

```bash
curl -X POST "https://dllni.mustafafares.com/api/v1/products/ai/generate-image" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Classic Chicken Burger",
    "description": "Golden bun, grilled chicken fillet, lettuce, and creamy sauce"
  }'
```

### 6.4 Success response (`200 OK`)

```json
{
  "data": {
    "imageBase64": "iVBORw0KGgoAAAANSUhEUgAA..."
  }
}
```

### 6.5 Fallback behavior

If AI generation fails internally, endpoint returns `200` with null image:

```json
{
  "data": {
    "imageBase64": null
  }
}
```

Client guidance:

- If `imageBase64` is null, show retry action and keep manual image upload enabled

### 6.6 Possible error responses

- `401 Unauthorized`
- `422 Unprocessable Entity`

Example 422 (missing title):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": [
      "The title field is required."
    ]
  }
}
```

---

## 7. Data dictionary

### 7.1 Product extraction object

| Field | Type | Nullable | Notes |
| ----- | ---- | -------- | ----- |
| title | string | yes | Null only when extraction fails |
| description | string | yes | Can be null when absent/failure |

### 7.2 Menu extraction item object

| Field | Type | Nullable | Notes |
| ----- | ---- | -------- | ----- |
| title | string | no | Empty/invalid items are filtered out by backend |
| description | string | yes | Optional AI output |

### 7.3 Generated image payload

| Field | Type | Nullable | Notes |
| ----- | ---- | -------- | ----- |
| imageBase64 | string | yes | Raw Base64 image bytes (no data URI prefix) |

---

## 8. Implementation notes for frontend/mobile

### 8.1 Decoding generated image

Backend returns raw Base64 only. To render in clients:

- Web image source prefix: `data:image/png;base64,`
- Mobile/webview: decode Base64 bytes directly and render as image bytes

### 8.2 Retry strategy

Recommended UX:

1. First failure: show inline retry action
2. Second failure: route user to manual product creation flow

### 8.3 Timeouts and UX

AI endpoints can be slower than standard CRUD APIs. Recommended:

- Show loading state with clear progress text
- Client timeout >= 60 seconds for AI calls
- Keep operation cancellable in UI

---

## 9. Postman examples

### 9.1 Extract from image (form-data)

- Method: `POST`
- URL: `{{base_url}}/api/v1/products/ai/extract-from-image`
- Headers:
  - `Authorization: Bearer {{token}}`
  - `Accept: application/json`
- Body (form-data):
  - `image` (type File)
  - `locale` (type Text, optional)

### 9.2 Extract from menu (form-data)

- Method: `POST`
- URL: `{{base_url}}/api/v1/products/ai/extract-from-menu`
- Headers:
  - `Authorization: Bearer {{token}}`
  - `Accept: application/json`
- Body (form-data):
  - `image` (type File)
  - `locale` (type Text, optional)

### 9.3 Generate image (raw JSON)

- Method: `POST`
- URL: `{{base_url}}/api/v1/products/ai/generate-image`
- Headers:
  - `Authorization: Bearer {{token}}`
  - `Accept: application/json`
  - `Content-Type: application/json`
- Body:

```json
{
  "title": "Classic Chicken Burger",
  "description": "Golden bun, grilled chicken fillet, lettuce, and creamy sauce"
}
```

---

## 10. Changelog policy

When backend behavior changes for these endpoints, update this contract in the same PR with:

- Changed fields
- New validation constraints
- New status codes or fallback behavior
