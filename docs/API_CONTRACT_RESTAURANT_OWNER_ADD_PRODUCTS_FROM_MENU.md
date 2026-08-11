# API Contract - Restaurant Owner Add Products From Menu

**Audience:** Flutter developers working on the Restaurant Owner app  
**Base URL:** `https://dllni.mustafafares.com`  
**API prefix:** `/api/v1`  
**Auth:** Laravel Sanctum bearer token  
**Main screen:** `AddProductMenuScreen` / route `/products/new_product/menu`

---

## 1. Goal

This document explains the API flow behind the Arabic screen used to:

1. Upload a menu image.
2. Analyze the image using AI to extract product names and descriptions.
3. Show editable product cards to the owner.
4. Submit each extracted product to the restaurant menu.

The backend currently creates products one by one. There is no bulk-create endpoint for menu items, so Flutter should loop over the extracted products and call the create-product endpoint once per product.

---

## 2. Authentication

All endpoints below require:

```http
Authorization: Bearer {token}
Accept: application/json
```

The token must belong to a restaurant seller / owner account that has a restaurant attached.

Do **not** send `restaurantId` from Flutter when creating a product. The backend derives the restaurant from the authenticated owner.

---

## 3. Full Flutter Flow

### Step 1 - Load categories

Flutter must load categories before submitting products because product creation requires `categoryId`.

```http
GET /api/v1/categories?perPage=20&page=1
```

Use the selected category id. If the screen does not have category selection yet, the current Flutter behavior can use the first available category, but this should be treated as a fallback only.

If no category exists, block submission and show:

```text
لا توجد تصنيفات متاحة لإضافة المنتجات
```

---

### Step 2 - Pick menu image locally

The user picks an image from gallery.

Frontend validation recommendation:

| Rule | Value |
| ---- | ----- |
| Allowed types | `jpg`, `jpeg`, `png`, `webp` |
| Recommended max before AI analysis | 5 MB based on UI copy |
| Backend AI menu image max | 12 MB |
| Backend product image max | 2 MB |

Important: the same image is used twice in the current flow:

1. As `image` for AI menu extraction.
2. As `primaryImage` for every created product.

Because product creation accepts only 2 MB for `primaryImage`, Flutter should compress/resize the selected image before submitting products if it is larger than 2 MB.

---

### Step 3 - Analyze menu image

Triggered by the `تحليل الصورة` button.

```http
POST /api/v1/products/ai/extract-from-menu
Content-Type: multipart/form-data
```

#### Request fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `image` | file | yes | The selected menu image. |
| `locale` | string | no | `ar` or `en`. Send current app locale when available. |

#### cURL example

```bash
curl -X POST "https://dllni.mustafafares.com/api/v1/products/ai/extract-from-menu" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -F "image=@/path/to/menu.jpg" \
  -F "locale=ar"
```

#### Success response - products found

```json
{
  "data": {
    "items": [
      {
        "title": "لحمة بالكرز",
        "description": "طبق لحمة بكرز بسوريا"
      },
      {
        "title": "كبة مشوية",
        "description": "كبة مشوية مع توابل شامية"
      }
    ]
  }
}
```

#### Success response - no products found

```json
{
  "data": {
    "items": []
  }
}
```

Flutter behavior:

- While request is running, disable `تحليل الصورة` and show loading text مثل `جاري تحليل الصورة...`.
- If `items` is empty, show manual fallback message.
- If request fails, show error toast.

---

### Step 4 - Show editable product cards

For each item from `data.items`, Flutter should render:

| UI field | API source | Notes |
| -------- | ---------- | ----- |
| `اسم المنتج` | `item.title` | Required before submit. |
| `وصف المنتج` | `item.description` | Optional but recommended. |

Before submit:

- Trim spaces from title and description.
- Skip any product with empty title.
- Allow the owner to edit extracted values before submission.

---

### Step 5 - Add products to menu

Triggered by the `إضافة للقائمة` button.

There is no bulk endpoint. Flutter should iterate over the extracted/edited products and call product creation for each valid product.

Recommended endpoint for owner app:

```http
POST /api/v1/restaurant-owner/products
Content-Type: multipart/form-data
```

Current generic endpoint also exists:

```http
POST /api/v1/products
Content-Type: multipart/form-data
```

For the owner app, prefer `/api/v1/restaurant-owner/products` because it is scoped under the restaurant owner route group.

#### Request fields per product

| Field | Type | Required | Current value from this screen | Notes |
| ----- | ---- | -------- | ------------------------------ | ----- |
| `categoryId` | integer | yes | selected category id or first category fallback | Must exist in `categories.id`. |
| `name` | string | yes | product title | Max 255. |
| `description` | string | no | product description | Nullable. |
| `price` | numeric | yes | `0` if screen has no price field | Backend requires it. |
| `discountedPrice` | numeric | no | `0` or omit/null | Must be >= 0 when sent. |
| `isAvailable` | boolean/int | no | `1` | Default available. |
| `stockQuantity` | integer | no | omit | Optional. |
| `lowStockThreshold` | integer | no | `1` | Optional. |
| `preparationTime` | integer | no | `0` | Minutes. |
| `isFeatured` | boolean/int | no | `1` or `0` | Optional. |
| `primaryImage` | file | no by backend, required by this screen flow | Selected menu image. Max 2 MB. |
| `images[]` | file[] | no | empty list | Extra product images. |

#### Multipart example for one product

```bash
curl -X POST "https://dllni.mustafafares.com/api/v1/restaurant-owner/products" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -F "categoryId=12" \
  -F "name=لحمة بالكرز" \
  -F "description=طبق لحمة بكرز بسوريا" \
  -F "price=0" \
  -F "discountedPrice=0" \
  -F "isAvailable=1" \
  -F "lowStockThreshold=1" \
  -F "preparationTime=0" \
  -F "isFeatured=1" \
  -F "primaryImage=@/path/to/menu-compressed.jpg"
```

#### Success response - `201/200`

The backend returns a product resource:

```json
{
  "data": {
    "id": 101,
    "restaurantId": 5,
    "categoryId": 12,
    "name": "لحمة بالكرز",
    "description": "طبق لحمة بكرز بسوريا",
    "price": 0,
    "discountedPrice": 0,
    "isFavorite": false,
    "cartQuantity": 0,
    "isAvailable": true,
    "isAvailableNow": true,
    "availabilityMode": "available",
    "unavailableUntil": null,
    "availabilityNote": null,
    "stockQuantity": null,
    "lowStockThreshold": 1,
    "preparationTime": 0,
    "isFeatured": true,
    "restaurant": {
      "id": 5,
      "name": "Restaurant Name"
    },
    "category": {
      "id": 12,
      "name": "أطباق رئيسية"
    },
    "modifierGroups": [],
    "substitutions": [],
    "primaryImage": "https://.../image.jpg",
    "images": [],
    "createdAt": "2026-07-05 10:30:00",
    "updatedAt": "2026-07-05 10:30:00"
  }
}
```

---

## 4. Recommended Flutter implementation logic

Pseudo-flow:

```dart
// 1. User picks image.
final file = File(imagePath);

// 2. User taps تحليل الصورة.
// Disable button while generateAiProductDataFromMenuStatus == BlocStatus.loading.
context.read<ProductsBloc>().add(
  GenerateAiProductDataFromMenuEvent(
    params: GenerateAiProductDataFromMenuParams(
      image: file,
      locale: resolveAiLocale(context),
    ),
  ),
);

// 3. Render returned items and allow editing.

// 4. User taps إضافة للقائمة.
// Disable button while postProductsFromMenuStatus == BlocStatus.loading.
for (final product in editedProducts) {
  await postProduct(
    categoryId: selectedCategoryId,
    name: product.title.trim(),
    description: product.description.trim(),
    price: '0',
    discountedPrice: '0',
    lowStockThreshold: '1',
    preparationTime: '0',
    primaryImage: compressedMenuImage,
  );
}
```

Button states:

| Button | Loading state source | Disabled when |
| ------ | -------------------- | ------------- |
| `تحليل الصورة` | `generateAiProductDataFromMenuStatus` | status is `loading` |
| `إضافة للقائمة` | `postProductsFromMenuStatus` | status is `loading` or no valid products |
| `إلغاء` | `postProductsFromMenuStatus` | status is `loading` |

---

## 5. Error responses

### 401 Unauthorized

Missing or invalid token.

```json
{
  "message": "Unauthenticated."
}
```

### 422 Validation error

Example: missing required product title.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": [
      "The name field is required."
    ]
  }
}
```

Example: product image too large.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "primaryImage": [
      "The primary image may not be greater than 2048 kilobytes."
    ]
  }
}
```

### Empty AI result

This is not an API error. Backend can return:

```json
{
  "data": {
    "items": []
  }
}
```

Flutter should show a friendly message and keep manual entry available.

---

## 6. Important backend constraints

1. `restaurantId` is prohibited in the product creation request. The server sets it from the authenticated restaurant owner.
2. `categoryId`, `name`, and `price` are required by backend validation.
3. `primaryImage` is optional in backend validation, but this screen should send it because the user selected a menu image.
4. The AI menu extraction endpoint accepts a larger image than product creation. Compress before create-product calls.
5. Creating several products from one menu image means several HTTP requests. If the backend later adds a true bulk endpoint, Flutter can replace the loop with one request.

---

## 7. Suggested future bulk endpoint (not currently implemented)

If we want to reduce network requests, backend can later add:

```http
POST /api/v1/restaurant-owner/products/from-menu
Content-Type: multipart/form-data
```

Suggested request:

| Field | Type | Required |
| ----- | ---- | -------- |
| `categoryId` | integer | yes |
| `image` | file | yes |
| `products[0][title]` | string | yes |
| `products[0][description]` | string | no |
| `products[1][title]` | string | yes |
| `products[1][description]` | string | no |

Suggested response:

```json
{
  "data": {
    "createdCount": 2,
    "products": [
      {
        "id": 101,
        "name": "لحمة بالكرز"
      },
      {
        "id": 102,
        "name": "كبة مشوية"
      }
    ]
  }
}
```

Until this endpoint exists, Flutter must use the existing loop over `POST /api/v1/restaurant-owner/products` or `POST /api/v1/products`.
