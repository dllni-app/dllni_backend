# API Contract – Authentication

**Audience:** Frontend / Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Two auth flows — **User** (app login with phone) and **Dashboard** (admin login with email, forgot password, me). For module endpoints (Restaurants, Cleaning, etc.), see the respective API contract docs.

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **API base:** `https://dllni.mustafafares.com/api/`
- **Protected endpoints:** Send the token: `Authorization: Bearer {token}`
- **Content-Type:** `application/json` for request bodies; responses are JSON.

---

## 2. Request/response conventions

- **JSON keys:** camelCase (e.g. `phone`, `emailVerifiedAt`, `permissions`).
- **Errors:** 4xx/5xx with JSON body; Laravel validation errors under `errors` (keyed by field).

---

## 3. User authentication (app)

For end-users: login with **phone + password**, logout. No permissions in response.

### 3.1 User login

| Method | Path         | Auth required | Description                         |
| ------ | ------------ | ------------- | ----------------------------------- |
| POST   | `/api/login` | No            | Authenticate with phone and password; returns user and token |

**Request body:**

```json
{
  "phone": "+962791234567",
  "password": "secret"
}
```

| Field    | Type   | Required | Description        |
| -------- | ------ | -------- | ------------------ |
| phone    | string | Yes      | User’s phone number |
| password | string | Yes      | User password      |

**Response (200 OK):**

```json
{
  "user": {
    "id": 1,
    "name": "John",
    "email": "john@example.com",
    "phone": "+962791234567",
    "emailVerifiedAt": null,
    "primaryImage": null,
    "images": [],
    "createdAt": "2025-02-21T12:00:00.000000Z",
    "updatedAt": "2025-02-21T12:00:00.000000Z"
  },
  "token": "2|xyz789..."
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

### 3.2 User logout

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

## 4. Dashboard authentication (admin)

For dashboard/admin: login with **email + password**, forgot password, logout, me. Responses include **permissions**.

**Base path:** `/api/dashboard/`

### 4.1 Dashboard login

| Method | Path                      | Auth required | Description                                    |
| ------ | ------------------------- | ------------- | ---------------------------------------------- |
| POST   | `/api/dashboard/login`    | No            | Authenticate with email/password; returns user, permissions, token |

**Request body:**

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

**Response (200 OK):**

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
    "products.delete"
  ],
  "token": "1|abc123..."
}
```

- **permissions:** Array of permission names for dashboard UI (e.g. menus, actions). Format: `{group}.{action}`.

**Error (422)** – Invalid credentials: `errors.email` with message.

---

### 4.2 Dashboard forgot password

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

### 4.3 Dashboard logout

| Method | Path                      | Auth required | Description         |
| ------ | ------------------------- | ------------- | ------------------- |
| POST   | `/api/dashboard/logout`   | Yes (Bearer)  | Revoke current token |

**Response (200 OK):** `{ "message": "Logged out successfully." }`  
**Error (401):** Missing or invalid token.

---

### 4.4 Dashboard me (current user)

| Method | Path                   | Auth required | Description                    |
| ------ | ---------------------- | ------------- | ------------------------------ |
| GET    | `/api/dashboard/me`    | Yes (Bearer)  | Return current user and permissions |

**Response (200 OK):**

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
  "permissions": ["orders.view", "orders.create", "orders.update", "orders.delete", "products.view", "products.create", "products.update", "products.delete"]
}
```

No `token` in response; use existing Bearer token.

**Error (401):** Missing or invalid token.

---

## 5. Permission names (dashboard only)

Permissions follow `{group}.{action}`. Actions: `view`, `create`, `update`, `delete`. Example groups: `orders`, `products`, `inventory`, `offers`, `staff`, `reports`, `settings`, `bookings`, `workers`, `disputes`, `system_alerts`, `pricing`, `catalog`. Use the `permissions` array from dashboard login/me for UI (menus, actions).

---

## 6. Quick reference

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
