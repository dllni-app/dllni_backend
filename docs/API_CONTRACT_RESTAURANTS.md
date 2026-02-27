# API Contract – Restaurant Module

**Audience:** Frontend / mobile developers  
**Base URL:** `https://dllni.mustafafares.com`  
**API prefix:** `/api/v1/`  
**Auth:** Laravel Sanctum — `Authorization: Bearer {token}` on all requests.

---

## Scope

This document is the **entry point** for the Restaurant module API. It lists all endpoint groups and points to the full specification where applicable.

**Full request/response details, validation rules, and examples:** [API_CONTRACT_RESTAURANTS_DASHBOARD.md](API_CONTRACT_RESTAURANTS_DASHBOARD.md) (Restaurant Admin Dashboard contract).

---

## Endpoint summary

All paths below are relative to `/api/v1/`. All require authentication unless noted.

| Section | Endpoints | Description |
| ------- | --------- | ------------ |
| **Dashboard** | `GET /restaurant/dashboard/overview` | KPIs (sales, orders, disputes, low stock) |
| **Restaurants** | CRUD ` /restaurants`, ` /restaurants/{id}/operating-hours` | Restaurant CRUD and operating hours |
| **Categories** | CRUD ` /categories` | Product categories |
| **Products** | CRUD ` /products` | Product CRUD |
| **Products – AI (Gemini)** | `POST /products/ai/extract-from-image`, `POST /products/ai/extract-from-menu`, `POST /products/ai/generate-image` | AI: extract title/description from image, extract items from menu image, generate product image from text |
| **Orders** | CRUD ` /orders`, `POST /orders/{id}/accept`, `POST /orders/{id}/reject`, `GET /orders/{id}/invoice` | Orders and accept/reject/invoice |
| **Offers** | CRUD ` /offers` | Offers |
| **Promo codes** | CRUD ` /promo-codes` | Promo codes |
| **Inventory** | CRUD ` /inventory-items`, `GET /restaurant/inventory-summary`, `GET /restaurant/inventory-alerts` | Inventory items, summary, alerts |
| **Disputes** | CRUD ` /restaurant-order-disputes` | Order disputes |
| **Documents** | CRUD ` /restaurant-documents` | Restaurant documents |
| **Reputation & penalties** | GET ` /restaurant-reputation-logs`, ` /restaurant-penalties` | Read-only |
| **Staff & roles** | CRUD ` /restaurant-staff`, ` /restaurant-roles`, `PUT /restaurant-roles/{id}/permissions` | Staff and role management |
| **Analytics** | `GET /restaurant/analytics/daily-stats`, ` /restaurant/analytics/monthly-stats` | Daily and monthly stats |
| **Assistant, recurring, reviews** | GET ` /restaurant-assistant-queries`, ` /restaurant-recurring-orders`, ` /reviews` | Read-only |
| **Product search** | `GET /restaurant/search/products` | Full-text product search (non-AI) |
| **Cancellation policy** | `GET /cancellation-policy?module=restaurant` | Active cancellation policy |

---

## Products – AI-assisted (Gemini)

Used in the “Add product” flows (e.g. إضافة منتج جديد):

- **Extract from product image:** `POST /api/v1/products/ai/extract-from-image`  
  - Body: `multipart/form-data` with `image` (required), optional `locale` (`ar` \| `en`).  
  - Response: `{ "data": { "title", "description" } }`.

- **Extract from menu image:** `POST /api/v1/products/ai/extract-from-menu`  
  - Body: `multipart/form-data` with `image` (required), optional `locale`.  
  - Response: `{ "data": { "items": [ { "title", "description" }, ... ] } }`.

- **Generate product image:** `POST /api/v1/products/ai/generate-image`  
  - Body: JSON `{ "title", "description?" }`.  
  - Response: `{ "data": { "imageBase64" } }` (base64 PNG or `null`).

Full validation, error codes, and examples: **§3.4a** in [API_CONTRACT_RESTAURANTS_DASHBOARD.md](API_CONTRACT_RESTAURANTS_DASHBOARD.md).

---

## Conventions

- **JSON:** camelCase for all keys.
- **Pagination:** `perPage` (1–100, default 20), `page`; response has `data`, `links`, `meta`.
- **Filters:** `filter[fieldName]=value`; **sort:** `sort=field` or `sort=-field`.
- **Errors:** 4xx/5xx with JSON; validation errors under `errors` keyed by field.

See [API_CONTRACT_RESTAURANTS_DASHBOARD.md](API_CONTRACT_RESTAURANTS_DASHBOARD.md) for client behavior, enums, and example requests/responses.
