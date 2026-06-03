# Product AI Image Generation Reuse Guide

## Status in this project

This project already implements AI-assisted product image features, but the implementation is **not** direct image-to-image editing.

What exists:

- **Image -> text extraction** for a single product photo
- **Image -> text extraction** for a menu photo
- **Text -> image generation** for product preview images

What does **not** exist:

- A single endpoint that accepts an image and returns a modified/generated image from that same image

If you need true image-to-image behavior in another project, you must add a separate endpoint and use a model/API that supports image editing.

---

## Relevant files in this repository

- `app/Http/Controllers/API/ProductAiController.php`
- `app/Http/Requests/ProductAi/ExtractFromProductImageRequest.php`
- `app/Http/Requests/ProductAi/ExtractFromMenuImageRequest.php`
- `app/Http/Requests/ProductAi/GenerateProductImageRequest.php`
- `app/Services/GeminiProductService.php`
- `config/gemini.php`
- `modules/Resturants/routes/api.php`
- `modules/Supermarket/routes/api.php`
- `tests/Unit/Services/GeminiProductServiceTest.php`
- `tests/Feature/Restaurant/ProductAiEndpointsTest.php`
- `tests/Feature/SmProduct/SmProductAiEndpointsTest.php`

---

## Available endpoints

### 1. Extract a product from an image

- `POST /api/v1/products/ai/extract-from-image`
- Request type: `multipart/form-data`
- Input: `image`, optional `locale`
- Output: `title`, `description`

### 2. Extract menu items from an image

- `POST /api/v1/products/ai/extract-from-menu`
- Request type: `multipart/form-data`
- Input: `image`, optional `locale`
- Output: `items[]`

### 3. Generate a product image from text

- `POST /api/v1/products/ai/generate-image`
- Request type: `application/json`
- Input: `title`, optional `description`
- Output: `imageBase64`

The same controller is also reused for supermarket routes:

- `POST /api/v1/sm-products/ai/generate-image`
- `POST /api/v1/sm-products/ai/extract-from-image`
- `POST /api/v1/sm-products/ai/extract-from-menu`

---

## How the generation flow works

### A. Validate the request

`GenerateProductImageRequest` enforces:

- `title` is required, string, max 255
- `description` is optional, nullable, string, max 2000

### B. Controller forwards to the shared service

`ProductAiController::generateImage()` calls:

```php
$this->gemini->generateProductImage($title, $description);
```

### C. Service builds the prompt

`GeminiProductService::buildImagePrompt()` creates a catalog-style prompt:

- product name
- optional product description
- clean studio background
- soft lighting
- realistic style
- no text or watermarks
- centered composition
- exact `1:1` output

### D. Gemini request

The service posts to:

```text
{GEMINI_BASE_URL}/models/{GEMINI_IMAGE_GEN_MODEL}:generateContent
```

The payload uses:

- `responseModalities: ['IMAGE']`
- `imageConfig.aspectRatio = 1:1`
- `imageConfig.imageSize = 1K`

### E. Parse the response

The service looks for the first `inline_data.data` value in the Gemini response and returns it as raw Base64.

### F. API response

Success response:

```json
{
  "data": {
    "imageBase64": "iVBORw0KGgoAAAANSUhEUgAA..."
  }
}
```

Failure fallback:

```json
{
  "data": {
    "imageBase64": null
  }
}
```

---

## Composite flow for "image -> generated image"

If you want to start from an uploaded image and end with a newly generated catalog image, the current project expects a **two-step** flow:

1. Send the original image to `extract-from-image`
2. Use the returned `title` and `description` in `generate-image`

That is the closest reusable pattern in this codebase for "generate image from image".

---

## Reuse checklist for another Laravel backend

### 1. Add a shared config file

Create something like `config/gemini.php` with:

- `api_key`
- `base_url`
- `vision_model`
- `image_gen_model`
- `timeout`
- `retry_times`
- `retry_sleep`

### 2. Create a service class

Move the Gemini call logic into a dedicated service.

Recommended responsibilities:

- build prompts
- send Gemini requests
- parse inline image data
- return safe fallbacks

### 3. Add request validation

Use Form Request classes for:

- uploaded image extraction requests
- text-to-image generation requests

### 4. Keep the response shape stable

The current contract is simple:

- extraction endpoints return `data`
- generation endpoint returns `data.imageBase64`
- failures return `200` with null or empty fallback values

### 5. Register routes under auth

The current implementation is protected with Sanctum:

- `auth:sanctum`

### 6. Add tests

Minimum tests to port:

- service returns a Base64 image when Gemini responds with inline image data
- extraction works from uploaded image
- invalid/missing input returns validation errors
- Gemini failure returns safe fallback

---

## Environment variables used

- `GEMINI_API_KEY`
- `GEMINI_BASE_URL`
- `GEMINI_VISION_MODEL`
- `GEMINI_IMAGE_GEN_MODEL`
- `GEMINI_TIMEOUT`
- `GEMINI_RETRY_TIMES`
- `GEMINI_RETRY_SLEEP_MS`

---

## Important implementation notes

- The returned `imageBase64` has **no** `data:image/...;base64,` prefix
- Client code must prepend the prefix when rendering in a browser
- The service uses the `x-goog-api-key` header, not a query string key
- Current fallback behavior is intentionally soft: the API returns `200` and null data on Gemini failure

---

## Bottom line

This project already has reusable AI image support, but it is:

- **image -> text** for extraction
- **text -> image** for generation

It is **not** a direct image editing pipeline.
