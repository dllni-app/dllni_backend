# API Contract: User Cleaning Banners

## Purpose
This contract defines the public banner feed used by the cleaning section in the user app.

- Base path: `/api/v1/user/cleaning/home`
- Endpoint: `GET /api/v1/user/cleaning/home/banners`
- Auth: not required
- Content-Type: `application/json`

The endpoint returns only banners that are currently active and within their visibility window.

---

## Supported Endpoint

### 1) List cleaning banners
`GET /api/v1/user/cleaning/home/banners`

#### Query params
None.

#### Success response
HTTP `200 OK`

```json
{
  "banners": [
    {
      "id": 1,
      "title": "Spring cleaning offer",
      "subtitle": "Get 20% off your first deep clean",
      "imageUrl": "https://dllni.mustafafares.com/storage/cleaning-banners/spring-cleaning.jpg",
      "targetUrl": "https://dllni.mustafafares.com/cleaning/offers/1",
      "sortOrder": 1,
      "isActive": true,
      "startsAt": "2026-06-01T00:00:00+03:00",
      "endsAt": "2026-06-30T23:59:59+03:00"
    }
  ]
}
```

#### Field reference

| Field | Type | Description |
| --- | --- | --- |
| `banners` | array | Ordered banner list for the cleaning home carousel |
| `id` | integer | Banner record id |
| `title` | string | Main banner title |
| `subtitle` | string or null | Optional supporting text |
| `imageUrl` | string or null | Public image URL for the banner artwork |
| `targetUrl` | string or null | Destination URL or deep-link target used when tapping the banner |
| `sortOrder` | integer | Lower values appear first |
| `isActive` | boolean | Banner flag from the dashboard |
| `startsAt` | string or null | Visibility window start in ISO 8601 format |
| `endsAt` | string or null | Visibility window end in ISO 8601 format |

---

## Visibility Rules

The backend returns a banner only when all of the following are true:

- `isActive = true`
- `startsAt` is `null` or `<= now`
- `endsAt` is `null` or `>= now`

The list is sorted by:

1. `sortOrder` ascending
2. `id` ascending

---

## Flutter Notes

- Treat this as a home-carousel feed for the cleaning section.
- Render `imageUrl` as the primary visual.
- If `subtitle` is present, show it as overlay text or supporting copy.
- If `targetUrl` is present, open it on tap. Keep the navigation layer flexible because it may be an external URL or an in-app deep link.
- Cache the response locally if the screen is revisited often, because the payload is content-only and changes infrequently.

