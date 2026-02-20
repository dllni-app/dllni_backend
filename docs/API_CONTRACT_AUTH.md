# API Contract – Authentication

**Audience:** Frontend / Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Login, logout, forgot password, and current user (me). For module endpoints (Restaurants, Cleaning, etc.), see the respective API contract docs.

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **Auth endpoints base:** `https://dllni.mustafafares.com/api/` (no `v1` prefix).
- **Protected endpoints:** After login, send the token on every request:
  - Header: `Authorization: Bearer {token}`
- **Content-Type:** `application/json` for request bodies; responses are JSON.

---

## 2. Request/response conventions

- **JSON keys:** camelCase (e.g. `email`, `emailVerifiedAt`, `permissions`).
- **Errors:** 4xx/5xx with JSON body; Laravel validation errors under `errors` (keyed by field).

---

## 3. Auth endpoints

### 3.1 Login

| Method | Path         | Auth required | Description                    |
| ------ | ------------ | ------------- | ------------------------------ |
| POST   | `/api/login` | No            | Authenticate with email/password and receive token and permissions |

**Request body:**

```json
{
  "email": "admin@dllni.com",
  "password": "password"
}
```

| Field    | Type   | Required | Description |
| -------- | ------ | -------- | ----------- |
| email    | string | Yes      | Valid email address |
| password | string | Yes      | User password |

**Response (200 OK):**

```json
{
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@admin.com",
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
    "products.delete"
  ],
  "token": "1|abc123..."
}
```

- **user:** Current user object (camelCase). `primaryImage` and `images` are present when media are loaded; for login they are typically null/empty.
- **permissions:** Array of permission names (strings) the user has (via roles). Format: `{group}.{action}` (e.g. `orders.view`, `settings.update`).
- **token:** Sanctum API token; use as `Authorization: Bearer {token}` for protected endpoints.

**Error (422 Unprocessable Entity)** – Invalid credentials:

```json
{
  "message": "The provided credentials are incorrect.",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

**Error (422)** – Validation (e.g. missing email/password): `errors` keyed by field.

---

### 3.2 Forgot password

| Method | Path                   | Auth required | Description                |
| ------ | ---------------------- | ------------- | -------------------------- |
| POST   | `/api/forgot-password` | No            | Send password reset link to the given email |

**Request body:**

```json
{
  "email": "admin@dllni.com"
}
```

| Field | Type   | Required | Description |
| ----- | ------ | -------- | ----------- |
| email | string | Yes      | Must exist in `users` table |

**Response (200 OK):**

```json
{
  "message": "We have emailed your password reset link!"
}
```

**Error (422)** – Email not found or validation:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["We could not find a user with that email address."]
  }
}
```

The reset link in the email points to the app’s password-reset URL (e.g. frontend or web route). Configure `app.frontend_url` for redirect target.

---

### 3.3 Logout

| Method | Path          | Auth required | Description           |
| ------ | ------------- | ------------- | --------------------- |
| POST   | `/api/logout` | Yes (Bearer)  | Revoke current token  |

**Headers:** `Authorization: Bearer {token}`

**Request body:** None.

**Response (200 OK):**

```json
{
  "message": "Logged out successfully."
}
```

**Error (401 Unauthorized):** Missing or invalid token.

---

### 3.4 Me (current user)

| Method | Path      | Auth required | Description                          |
| ------ | --------- | ------------- | ------------------------------------ |
| GET    | `/api/me` | Yes (Bearer)  | Return current user and permissions |

**Headers:** `Authorization: Bearer {token}`

**Response (200 OK):**

```json
{
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@dllni.com",
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
    "products.delete"
  ]
}
```

Same `user` shape as login; **permissions** is the list of permission names for the authenticated user. No `token` in response (use existing token).

**Error (401 Unauthorized):** Missing or invalid token.

---

## 4. Permission names (dashboard)

Permissions follow the pattern `{group}.{action}`. Actions: `view`, `create`, `update`, `delete`. Groups align with dashboard areas, e.g.:

| Group         | Example permissions        |
| ------------- | -------------------------- |
| orders        | `orders.view`, `orders.create`, … |
| products      | `products.view`, `products.create`, … |
| inventory     | `inventory.view`, …         |
| offers        | `offers.view`, …            |
| staff         | `staff.view`, …             |
| reports       | `reports.view`, …           |
| settings      | `settings.view`, …          |
| bookings      | `bookings.view`, …          |
| workers       | `workers.view`, …           |
| disputes      | `disputes.view`, …          |
| system_alerts | `system_alerts.view`, …     |
| pricing       | `pricing.view`, …           |
| catalog       | `catalog.view`, …           |

Use the `permissions` array from **login** or **me** to drive UI (e.g. show/hide menu items or actions).

---

## 5. Quick reference

| Method | Path                   | Auth  | Description           |
| ------ | ---------------------- | ----- | --------------------- |
| POST   | `/api/login`           | No    | Login (email + password) |
| POST   | `/api/forgot-password` | No    | Send reset link       |
| POST   | `/api/logout`          | Bearer| Revoke token          |
| GET    | `/api/me`              | Bearer| Current user + permissions |
