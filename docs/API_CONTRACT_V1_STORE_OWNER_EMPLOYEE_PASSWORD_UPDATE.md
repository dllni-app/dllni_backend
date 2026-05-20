# API Contract: Store Owner Employee Password Update

## 1. Overview

This contract documents the endpoint used by a supermarket store owner/admin to update an employee password.

- Scope: Supermarket owner app
- Auth: Required (`auth:sanctum`)
- Role: Authenticated user must be `supermarket_seller`
- Ownership: Employee must belong to one of the authenticated owner's stores

---

## 2. Endpoint

- Method: `PATCH`
- URL: `/api/v1/store-owner/employees/{staff}/password`
- Content-Type: `application/json`

### Path params

- `staff` (integer, required): `sm_store_staff.id`

---

## 3. Request Body

```json
{
  "newPassword": "NewPass123!",
  "newPasswordConfirmation": "NewPass123!"
}
```

### Validation rules

- `newPassword`: required, string, min `8`, max `255`
- `newPasswordConfirmation`: required, string, must equal `newPassword`

---

## 4. Success Response

### 200 OK

```json
{
  "message": "Employee password updated successfully."
}
```

---

## 5. Error Responses

### 401 Unauthorized

When token is missing/invalid.

```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden

When authenticated user is not a supermarket seller, or tries to update staff outside owned store context.

Possible messages include:

```json
{
  "message": "This endpoint is for supermarket sellers only."
}
```

or

```json
{
  "message": "You do not have access to this employee."
}
```

### 422 Unprocessable Entity

Validation errors (password rules, confirmation mismatch, or employee without linked user account).

Example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "newPassword": [
      "The new password field is required."
    ],
    "newPasswordConfirmation": [
      "The new password confirmation and new password must match."
    ]
  }
}
```

Employee has no linked user account:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "staff": [
      "This employee is not linked to a user account."
    ]
  }
}
```

---

## 6. cURL Example

```bash
curl --request PATCH \
  --url 'https://YOUR_DOMAIN/api/v1/store-owner/employees/123/password' \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer YOUR_TOKEN' \
  --header 'Content-Type: application/json' \
  --data '{
    "newPassword": "NewPass123!",
    "newPasswordConfirmation": "NewPass123!"
  }'
```

---

## 7. Backend Mapping

- Route: `Modules/Supermarket/routes/api.php`
- Controller: `Modules/Supermarket/app/Http/Controllers/API/StoreOwner/StoreOwnerEmployeePasswordUpdateController.php`
- Request validation: `Modules/Supermarket/app/Http/Requests/StoreOwnerEmployeePasswordUpdateRequest.php`
