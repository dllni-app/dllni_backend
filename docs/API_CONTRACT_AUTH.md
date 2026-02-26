# API Contract – Authentication

**Audience:** Frontend / Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Two auth flows — **User** (app login with phone) and **Dashboard** (admin login with email, forgot password, me). For module endpoints (Restaurants, Cleaning, etc.), see the respective API contract docs.

---

## 1. Seeded test users

The following users are created by the database seeders (`DashboardPermissionsSeeder`, `AdminUserSeeder`, `CleaningWorkerAndSellerSeeder`). Use these for development and testing.

| Type            | Login (email or phone)   | Password  | Role / Notes |
| --------------- | ------------------------ | --------- | ------------ |
| **Dashboard admin** | `admin@admin.com`        | `password` | Role: `admin`. All dashboard permissions (orders, products, inventory, offers, stores, staff, reports, settings, bookings, workers, disputes, system_alerts, pricing, catalog). |
| **User (cleaning worker)** | `+962790000001` (phone)  | `password` | Role: `cleaning_worker`. Cleaning API permissions (bookings, services, etc.). Has linked Worker record. |
| **User (restaurant seller)** | `+962790000002` (phone)  | `password` | Role: `restaurant_seller`. Seller API permissions (restaurants, orders, products, etc.). Has linked Restaurant. |

- **Dashboard:** use **email** + password with `POST /api/dashboard/login`.
- **User (app):** use **phone** + password with `POST /api/login`.

---

## 2. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **API base:** `https://dllni.mustafafares.com/api/`
- **Protected endpoints:** Send the token: `Authorization: Bearer {token}`
- **Content-Type:** `application/json` for request bodies; responses are JSON.

### 2.1 Client behavior (UI/API usage)

All GET (with id/enum), POST, and PUT usage must follow the client behavior rules in [API_CONTRACT_CLIENT_BEHAVIOR.md](API_CONTRACT_CLIENT_BEHAVIOR.md): select menu for id/enum in GET (user sees label only); backend-known data stored in page state and not shown/editable in POST; one dedicated input per user-supplied field in POST; optimistic local update for PUT, then persist on success or revert on failure.

---

## 3. Request/response conventions

- **JSON keys:** camelCase (e.g. `phone`, `emailVerifiedAt`, `permissions`).
- **Errors:** 4xx/5xx with JSON body; Laravel validation errors under `errors` (keyed by field).

---

## 4. User authentication (app)

For end-users: login with **phone + password**, logout. No permissions in response.

### 4.1 User login

| Method | Path         | Auth required | Description             https://dllni.mustafafares.com/api/login            |
| ------ | ------------ | ------------- | ----------------------------------- |
| POST   | `/api/login` | No            | Authenticate with phone and password; returns user and token |

**Request body (example — seeded cleaning worker):**

```json
{
  "phone": "+962790000001",
  "password": "password"
}
```

| Field    | Type   | Required | Description        |
| -------- | ------ | -------- | ------------------ |
| phone    | string | Yes      | User’s phone number |
| password | string | Yes      | User password      |

**Response (200 OK) — example (seeded cleaning worker):**

```json
{
  "user": {
    "id": 2,
    "name": "Cleaning Worker",
    "email": "cleaning.worker@example.com",
    "phone": "+962790000001",
    "emailVerifiedAt": "2025-02-21T12:00:00.000000Z",
    "primaryImage": null,
    "images": [],
    "createdAt": "2025-02-21T12:00:00.000000Z",
    "updatedAt": "2025-02-21T12:00:00.000000Z"
  },
  "token": "2|abc..."
}
```

- **user:** Current user (camelCase). Use `token` as `Authorization: Bearer {token}` for protected app endpoints.

**Error (422)** – Invalid credentials or validation:

```json
{
  "message": "The provided credentials are incorrect.",
  "errors": {
    "phone": ["The provided credentials are incorrect."]
  }
}
```

---

### 4.2 User logout

| Method | Path          | Auth required | Description          |
| ------ | ------------- | ------------- | -------------------- |
| POST   | `/api/logout` | Yes (Bearer)  | Revoke current token |

**Headers:** `Authorization: Bearer {token}` (token from user login).

**Response (200 OK):**

```json
{
  "message": "Logged out successfully."
}
```

**Error (401):** Missing or invalid token.

---

## 5. Dashboard authentication (admin)

For dashboard/admin: login with **email + password**, forgot password, logout, me. Responses include **permissions**.

**Base path:** `/api/dashboard/`

### 5.1 Dashboard login

| Method | Path                      | Auth required | Description                                    |
| ------ | ------------------------- | ------------- | ---------------------------------------------- |
| POST   | `/api/dashboard/login`    | No            | Authenticate with email/password; returns user, permissions, token |

**Request body (example — seeded admin):**

```json
{
  "email": "admin@admin.com",
  "password": "password"
}
```

| Field    | Type   | Required | Description        |
| -------- | ------ | -------- | ------------------ |
| email    | string | Yes      | Valid email        |
| password | string | Yes      | User password      |

**Response (200 OK) — example (seeded admin; permissions are all dashboard groups):**

```json
{
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@admin.com",
    "phone": null,
    "emailVerifiedAt": "2025-02-21T12:00:00.000000Z",
    "primaryImage": null,
    "images": [],
    "createdAt": "2025-02-21T12:00:00.000000Z",
    "updatedAt": "2025-02-21T12:00:00.000000Z"
  },
  "permissions": [
    "orders.view",
    "orders.create",
    "orders.update",
    "orders.delete",
    "products.view",
    "products.create",
    "products.update",
    "products.delete",
    "inventory.view",
    "inventory.create",
    "inventory.update",
    "inventory.delete",
    "offers.view",
    "offers.create",
    "offers.update",
    "offers.delete",
    "stores.view",
    "stores.create",
    "stores.update",
    "stores.delete",
    "staff.view",
    "staff.create",
    "staff.update",
    "staff.delete",
    "reports.view",
    "reports.create",
    "reports.update",
    "reports.delete",
    "settings.view",
    "settings.create",
    "settings.update",
    "settings.delete",
    "bookings.view",
    "bookings.create",
    "bookings.update",
    "bookings.delete",
    "workers.view",
    "workers.create",
    "workers.update",
    "workers.delete",
    "disputes.view",
    "disputes.create",
    "disputes.update",
    "disputes.delete",
    "system_alerts.view",
    "system_alerts.create",
    "system_alerts.update",
    "system_alerts.delete",
    "pricing.view",
    "pricing.create",
    "pricing.update",
    "pricing.delete",
    "catalog.view",
    "catalog.create",
    "catalog.update",
    "catalog.delete"
  ],
  "token": "1|abc..."
}
```

- **permissions:** Array of permission names for dashboard UI (e.g. menus, actions). Format: `{group}.{action}`.

**Error (422)** – Invalid credentials: `errors.email` with message.

---

### 5.2 Dashboard forgot password

| Method | Path                              | Auth required | Description              |
| ------ | --------------------------------- | ------------- | ------------------------ |
| POST   | `/api/dashboard/forgot-password`  | No            | Send reset link to email |

**Request body:**

```json
{
  "email": "admin@admin.com"
}
```

| Field | Type   | Required | Description                |
| ----- | ------ | -------- | -------------------------- |
| email | string | Yes      | Must exist in `users` table |

**Response (200 OK):**

```json
{
  "message": "We have emailed your password reset link!"
}
```

**Error (422)** – Email not found: `errors.email` (e.g. "We could not find a user with that email address.").

---

### 5.3 Dashboard logout

| Method | Path                      | Auth required | Description         |
| ------ | ------------------------- | ------------- | ------------------- |
| POST   | `/api/dashboard/logout`   | Yes (Bearer)  | Revoke current token |

**Response (200 OK):** `{ "message": "Logged out successfully." }`  
**Error (401):** Missing or invalid token.

---

### 5.4 Dashboard me (current user)

| Method | Path                   | Auth required | Description                    |
| ------ | ---------------------- | ------------- | ------------------------------ |
| GET    | `/api/dashboard/me`    | Yes (Bearer)  | Return current user and permissions |

**Response (200 OK) — same shape as dashboard login (seeded admin has all dashboard permissions listed in 5.1).**

```json
{
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@admin.com",
    "phone": null,
    "emailVerifiedAt": "2025-02-21T12:00:00.000000Z",
    "primaryImage": null,
    "images": [],
    "createdAt": "2025-02-21T12:00:00.000000Z",
    "updatedAt": "2025-02-21T12:00:00.000000Z"
  },
  "permissions": ["orders.view", "orders.create", "orders.update", "orders.delete", "products.view", "products.create", "products.update", "products.delete", "inventory.view", "inventory.create", "inventory.update", "inventory.delete", "offers.view", "offers.create", "offers.update", "offers.delete", "stores.view", "stores.create", "stores.update", "stores.delete", "staff.view", "staff.create", "staff.update", "staff.delete", "reports.view", "reports.create", "reports.update", "reports.delete", "settings.view", "settings.create", "settings.update", "settings.delete", "bookings.view", "bookings.create", "bookings.update", "bookings.delete", "workers.view", "workers.create", "workers.update", "workers.delete", "disputes.view", "disputes.create", "disputes.update", "disputes.delete", "system_alerts.view", "system_alerts.create", "system_alerts.update", "system_alerts.delete", "pricing.view", "pricing.create", "pricing.update", "pricing.delete", "catalog.view", "catalog.create", "catalog.update", "catalog.delete"]
}
```

No `token` in response; use existing Bearer token.

**Error (401):** Missing or invalid token.

---

## 6. Permission names

**Dashboard (admin role)**  
Permissions follow `{group}.{action}`. Actions: `view`, `create`, `update`, `delete`. Groups (from seeders): `orders`, `products`, `inventory`, `offers`, `stores`, `staff`, `reports`, `settings`, `bookings`, `workers`, `disputes`, `system_alerts`, `pricing`, `catalog`. Use the `permissions` array from dashboard login/me for UI (menus, actions).

**App roles (user login)**  
- **cleaning_worker:** permissions for cleaning API (e.g. `cleaning_bookings.view`, `cleaning_bookings.create`, `event_bookings.view`, `cleaning_services.view`, `worker_homepage.view`, `geographic_coverage.view`, etc.).  
- **restaurant_seller:** permissions for seller API (e.g. `seller_restaurants.view`, `seller_orders.view`, `seller_products.view`, etc.).  
User login/me responses do not include a `permissions` array; role/permission checks are applied on the server when calling module APIs.

---

## 7. Quick reference

**User (app)**  
| Method | Path           | Auth  | Description              |
| ------ | -------------- | ----- | ------------------------ |
| POST   | `/api/login`   | No    | Login (phone + password) |
| POST   | `/api/logout`  | Bearer| Revoke token             |

**Dashboard (admin)**  
| Method | Path                             | Auth  | Description                |
| ------ | -------------------------------- | ----- | -------------------------- |
| POST   | `/api/dashboard/login`          | No    | Login (email + password)   |
| POST   | `/api/dashboard/forgot-password`| No    | Send reset link            |
| POST   | `/api/dashboard/logout`         | Bearer| Revoke token               |
| GET    | `/api/dashboard/me`             | Bearer| Current user + permissions |
