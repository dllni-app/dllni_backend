# API contract: user product text normalization (`/api/v1/user/products/normalize-text`)

Route is registered in `Modules/User/routes/api.php` inside the authenticated user group (`auth:sanctum`).

**Base URL:** `{baseUrl}/api` (example: `http://Dllni.test/api`)

**Full path:** `/v1/user/products/normalize-text`

**Authentication:** `Authorization: Bearer {token}` (Laravel Sanctum)

**Content type:** `application/json`

**Response wrapper:** top-level `data`

**Primary use cases:**
- Restaurant flow: normalize free-text order/product notes into canonical item names.
- Supermarket flow: normalize shopping text into clean product list.

---

## 1. Endpoint summary

| Property | Value |
| --- | --- |
| Method | `POST` |
| Path | `/v1/user/products/normalize-text` |
| Success | `200 OK` |

Behavior:
- Accepts noisy text (multi-line or sentence style).
- Uses Gemini to extract and normalize product names.
- Uses `module` to choose restaurant dish normalization or supermarket shopping-kit normalization.
- Removes quantities/units/noise words when possible.
- Returns:
  - `items`: normalized product names array.
  - `normalizedText`: comma-separated normalized string.

---

## 2. Request body

| Field | Required | Type | Notes |
| --- | --- | --- | --- |
| `text` | Yes | string | Non-empty, max `5000` chars. |
| `module` | Yes | string | Allowed: `resturant`, `supermarket`. `resturant` returns prepared dishes/menu items; `supermarket` returns grocery products and expands prepared dishes into ingredients/preparation-kit products. |
| `locale` | No | string \| null | Allowed: `ar`, `en`. If omitted, backend infers from input context/model behavior. |

### Example request

```json
{
  "text": "شلونك موعلم بدي كيلو قشوان\n2 كيلو لحمة\n3 فروج نيء كيلو\nفاصولي",
  "module": "supermarket",
  "locale": "ar"
}
```

---

## 3. Success response

| Field | Type | Notes |
| --- | --- | --- |
| `data.items` | `string[]` | Ordered unique normalized names. |
| `data.normalizedText` | `string \| null` | Comma-separated normalized names. `null` when no items were extracted. |

### Example `200 OK`

```json
{
  "data": {
    "items": ["قشوان", "لحمة", "فروج نيء", "فاصولياء"],
    "normalizedText": "قشوان , لحمة , فروج نيء , فاصولياء"
  }
}
```

---

## 4. Error responses

### 401 Unauthorized

Missing/invalid bearer token.

### 422 Validation error

Returned when request body fails validation.

Possible validation cases:
- `text` missing.
- `text` empty/whitespace.
- `text` exceeds `5000` characters.
- `module` missing or not in allowed values (`resturant`, `supermarket`).
- `locale` not in allowed values (`ar`, `en`).

Example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "text": [
      "The text field is required."
    ]
  }
}
```

---

## 5. Notes for Flutter/mobile integration

- Send user raw text as-is in `text`; no client-side tokenization required.
- Prefer `locale: "ar"` for Arabic users to improve normalization quality.
- Use `data.items` for chips/list rendering.
- Use `data.normalizedText` for single-line preview, search prefill, or confirmation UI.
- If `items` is empty, allow user edit/retry.

---

## 6. cURL example

```bash
curl -X POST "{baseUrl}/api/v1/user/products/normalize-text" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "شلونك موعلم بدي كيلو قشوان\n2 كيلو لحمة\n3 فروج نيء كيلو\nفاصولي",
    "module": "supermarket",
    "locale": "ar"
  }'
```
