# API Contract - Cleaning Worker Working Hours

**Audience:** Flutter developers working on `dllni_cleaning_owner_app` / Cleaning Worker app  
**Base URL:** `https://dllni.mustafafares.com`  
**API prefix:** `/api/v1`  
**Auth:** Laravel Sanctum bearer token  
**Screen:** `ساعات العمل`

---

## 1. Endpoint Summary

Use these endpoints for the worker working-hours screen:

```http
GET /api/v1/cleaning/worker/working-hours
PUT /api/v1/cleaning/worker/working-hours
```

The same endpoints are also available under the account namespace:

```http
GET /api/v1/cleaning/worker/account/working-hours
PUT /api/v1/cleaning/worker/account/working-hours
```

Recommended for the account/settings screen:

```http
GET /api/v1/cleaning/worker/account/working-hours
PUT /api/v1/cleaning/worker/account/working-hours
```

Both route groups call the same backend controller and return the same response shape.

---

## 2. Authentication

All requests require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

The authenticated user must have an associated `worker` record.

If the user has no worker profile, backend returns:

```json
{
  "message": "User must have an associated worker."
}
```

---

## 3. GET Worker Working Hours

### Request

```http
GET /api/v1/cleaning/worker/account/working-hours
```

### Success Response

```json
{
  "data": {
    "defaultWorkingHours": {
      "sunday": {
        "available": false,
        "data": []
      },
      "monday": {
        "available": true,
        "data": [
          {
            "09:00": "17:00"
          }
        ]
      },
      "tuesday": {
        "available": true,
        "data": [
          {
            "09:00": "12:00"
          },
          {
            "14:00": "18:00"
          }
        ]
      },
      "wednesday": {
        "available": false,
        "data": []
      },
      "thursday": {
        "available": false,
        "data": []
      },
      "friday": {
        "available": false,
        "data": []
      },
      "saturday": {
        "available": false,
        "data": []
      }
    }
  }
}
```

### Response Fields

| Field | Type | Description |
| ----- | ---- | ----------- |
| `data.defaultWorkingHours` | object | Full weekly working-hours object. |
| `defaultWorkingHours.{day}.available` | boolean | Whether the worker is available on this day. |
| `defaultWorkingHours.{day}.data` | array | List of working periods for the day. |
| `defaultWorkingHours.{day}.data[]` | object | A single time range object with one key-value pair: `{ "start": "end" }`. |

Day keys are:

```text
sunday, monday, tuesday, wednesday, thursday, friday, saturday
```

---

## 4. PUT Update Worker Working Hours

### Request

```http
PUT /api/v1/cleaning/worker/account/working-hours
Content-Type: application/json
```

### Request Body

The backend requires all seven days inside `defaultWorkingHours`.

```json
{
  "defaultWorkingHours": {
    "sunday": {
      "available": false,
      "data": []
    },
    "monday": {
      "available": true,
      "data": [
        {
          "09:00": "17:00"
        }
      ]
    },
    "tuesday": {
      "available": true,
      "data": [
        {
          "09:00": "12:00"
        },
        {
          "14:00": "18:00"
        }
      ]
    },
    "wednesday": {
      "available": true,
      "data": [
        {
          "09:00": "12:00"
        }
      ]
    },
    "thursday": {
      "available": false,
      "data": []
    },
    "friday": {
      "available": false,
      "data": []
    },
    "saturday": {
      "available": false,
      "data": []
    }
  }
}
```

### Request Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `defaultWorkingHours` | object | yes | The full weekly schedule. |
| `defaultWorkingHours.sunday` | object | yes | Sunday schedule. |
| `defaultWorkingHours.monday` | object | yes | Monday schedule. |
| `defaultWorkingHours.tuesday` | object | yes | Tuesday schedule. |
| `defaultWorkingHours.wednesday` | object | yes | Wednesday schedule. |
| `defaultWorkingHours.thursday` | object | yes | Thursday schedule. |
| `defaultWorkingHours.friday` | object | yes | Friday schedule. |
| `defaultWorkingHours.saturday` | object | yes | Saturday schedule. |
| `defaultWorkingHours.{day}.available` | boolean | yes | `true` when the worker accepts work on this day. |
| `defaultWorkingHours.{day}.data` | array | no | Time periods for this day. Use `[]` when unavailable. |
| `defaultWorkingHours.{day}.data[]` | object | yes when period exists | Must contain exactly one time range key-value pair, e.g. `{ "09:00": "17:00" }`. |

---

## 5. Time Format

Time values must be strings in `HH:MM` 24-hour format.

Valid examples:

```text
09:00
12:30
17:00
23:59
```

Invalid examples:

```text
9 AM
09:00 AM
24:00
09:75
```

The backend validates each period as a single object with one start/end pair:

```json
{
  "09:00": "17:00"
}
```

Do not send this shape:

```json
{
  "startTime": "09:00",
  "endTime": "17:00"
}
```

---

## 6. UI Mapping for `ساعات العمل`

Use this mapping between backend keys and Arabic UI labels:

| Backend key | Arabic label | English label |
| ----------- | ------------ | ------------- |
| `sunday` | الأحد | Sunday |
| `monday` | الإثنين | Monday |
| `tuesday` | الثلاثاء | Tuesday |
| `wednesday` | الأربعاء | Wednesday |
| `thursday` | الخميس | Thursday |
| `friday` | الجمعة | Friday |
| `saturday` | السبت | Saturday |

For the screenshot UI:

- The day switch maps to `available`.
- The `من` time is the object key, for example `"09:00"`.
- The `إلى` time is the object value, for example `"12:00"`.
- The `إضافة فترة` button adds another object to the `data` array.
- The `حفظ التغييرات` button sends the full `defaultWorkingHours` object.

---

## 7. Save Behavior

The update endpoint stores the submitted `defaultWorkingHours` directly on the authenticated worker.

Backend normalization behavior:

1. Reads `defaultWorkingHours` from the request.
2. Loops through the submitted day keys.
3. Stores each day as:

```json
{
  "available": true,
  "data": [
    {
      "09:00": "17:00"
    }
  ]
}
```

4. Returns the fresh `WorkerWorkingHoursResource`.

Flutter should always send all seven days to avoid missing-day validation errors.

---

## 8. Success Response After Update

The `PUT` endpoint returns the same response shape as `GET`.

```json
{
  "data": {
    "defaultWorkingHours": {
      "wednesday": {
        "available": true,
        "data": [
          {
            "09:00": "12:00"
          }
        ]
      }
    }
  }
}
```

The real response normally contains all seven normalized day keys.

---

## 9. Error Responses

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

### 403 No Worker Profile

```json
{
  "message": "User must have an associated worker."
}
```

### 422 Validation Error - Missing Day

Because all days are required, missing a day can return a validation error:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "defaultWorkingHours.monday": [
      "The default working hours.monday field is required."
    ]
  }
}
```

### 422 Validation Error - Invalid Period Shape

Each period must be a single object with one time range:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "defaultWorkingHours.wednesday.data.0": [
      "Each period must be a single object with one time range, e.g. {\"10:00\": \"16:00\"}."
    ]
  }
}
```

### 422 Validation Error - Invalid Time Format

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "defaultWorkingHours.wednesday.data.0": [
      "Times must be in HH:MM format (e.g. 09:00, 23:00)."
    ]
  }
}
```

---

## 10. Flutter Implementation Notes

Recommended flow:

1. On screen open, call:

```http
GET /api/v1/cleaning/worker/account/working-hours
```

2. Convert each backend day key to the localized UI card.
3. When a day switch is off, set:

```json
{
  "available": false,
  "data": []
}
```

4. When a day switch is on, keep at least one valid period in `data`.
5. When the user taps `إضافة فترة`, append another single-pair object to `data`.
6. When the user taps `حفظ التغييرات`, call:

```http
PUT /api/v1/cleaning/worker/account/working-hours
```

7. Disable the save button while the request is loading.
8. On success, replace the local state with the returned response.

---

## 11. Example Flutter Payload for Screenshot State

For the visible `الأربعاء` card with one period from `09:00` to `12:00`:

```json
{
  "defaultWorkingHours": {
    "sunday": {
      "available": false,
      "data": []
    },
    "monday": {
      "available": false,
      "data": []
    },
    "tuesday": {
      "available": false,
      "data": []
    },
    "wednesday": {
      "available": true,
      "data": [
        {
          "09:00": "12:00"
        }
      ]
    },
    "thursday": {
      "available": false,
      "data": []
    },
    "friday": {
      "available": false,
      "data": []
    },
    "saturday": {
      "available": false,
      "data": []
    }
  }
}
```
