# API Contract: Unified Notifications (Phase 1)

## Scope

This contract defines the shared notification architecture for:

- `User` module
- `Cleaning` module
- `Resturants` module
- `Supermarket` module

Phase 1 channels:

- In-app database notifications (`database`)
- Firebase push notifications (`fcm`)

Realtime contracts remain module-specific and are not replaced in this phase.

---

## Canonical Type Format

All new notifications use canonical type keys:

`<module>.<entity>.<event>`

Examples:

- `cleaning.booking.new_order_request`
- `supermarket.smart_list.scheduled_order_sent`
- `restaurant.owner.system_announcement`

Backward compatibility:

- API payload still includes legacy `type` values for existing clients.
- `canonical_type` is added for new clients and migration.

---

## Shared Payload Schema

Every normalized notification item should expose:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string | Notification id |
| `type` | string \| null | Legacy type (compatibility) |
| `canonical_type` | string \| null | Canonical namespaced key |
| `module` | string \| null | `cleaning`, `supermarket`, `restaurant`, `user` |
| `category` | string | `orders`, `offers`, or `system` |
| `priority` | string | `high` or `normal` |
| `icon` | string \| null | Absolute URL to module icon when available |
| `title` | string | Localized |
| `body` | string | Localized |
| `data` | object | Deep-link/action metadata |
| `readAt` + `read_at` | string \| null | Both camelCase and snake_case for compatibility |
| `createdAt` + `created_at` | string | Both camelCase and snake_case for compatibility |

---

## Type Inventory And Templates

The source of truth is `config/notification_types.php`.

### Cleaning

| Canonical type | Legacy type | Category | Priority | Channels | Template vars |
| --- | --- | --- | --- | --- | --- |
| `cleaning.booking.new_order_request` | `new_order` | `orders` | `high` | `database`, `push` | `booking_number` |
| `cleaning.booking.extension_request` | `extension_request` | `orders` | `high` | `database`, `push` | none |
| `cleaning.booking.dispute_opened` | `dispute_opened` | `system` | `high` | `database`, `push` | none |

### Supermarket

| Canonical type | Legacy type | Category | Priority | Channels | Template vars |
| --- | --- | --- | --- | --- | --- |
| `supermarket.smart_list.scheduled_order_sent` | `smart_list_scheduled_order_sent` | `orders` | `high` | `database`, `push` | `smart_list_name`, `order_number` |
| `supermarket.smart_list.scheduled_order_failed` | `smart_list_scheduled_order_failed` | `system` | `high` | `database`, `push` | `smart_list_name`, `reason` |
| `supermarket.order.rejected` | `supermarket_order_rejected` | `orders` | `normal` | `database` | `order_number` |
| `supermarket.store.trust_warning` | `store_trust_warning` | `system` | `high` | `database` | `trust_score` |
| `supermarket.store.consecutive_rejections_alert` | `consecutive_rejections_alert` | `system` | `high` | `database` | `recent_cancelled_count` |

### Restaurant Owner

| Canonical type | Legacy type | Category | Priority | Channels | Template vars |
| --- | --- | --- | --- | --- | --- |
| `restaurant.owner.order_created` | `restaurant_owner_order_created` | `orders` | `high` | `database` | `order_number` |
| `restaurant.owner.order_cancelled` | `restaurant_owner_order_cancelled` | `orders` | `normal` | `database` | `order_number` |
| `restaurant.owner.offer_performance` | `restaurant_owner_offer_performance` | `offers` | `normal` | `database` | `offer_name` |
| `restaurant.owner.system_announcement` | `restaurant_owner_system_announcement` | `system` | `normal` | `database` | `announcement` |

### User

| Canonical type | Legacy type | Category | Priority | Channels | Template vars |
| --- | --- | --- | --- | --- | --- |
| `user.account.reminder` | `account` | `system` | `normal` | `database` | `message` |

---

## Localization

Each type defines templates for:

- `ar` (default locale)
- `en` (fallback locale)

Rendering resolves placeholders using template variables and falls back to configured default/fallback locale.

---

## Channel Routing Rules

Shared channel routing is config-driven:

- `database` channel always available when configured.
- `push` in config maps to `fcm` channel only when notifiable has a valid FCM token.

This prevents failed push attempts for users without registered device tokens.

---

## Firebase Data Payload Convention

Push `data` contains at least:

- `type`
- `canonical_type`
- `module`
- `category`
- `priority`

And merges type-specific metadata (for example: `bookingId`, `timeWarningId`, `disputeId`, `orderNumber`).

---

## Migration Notes

- Existing notification endpoints remain unchanged.
- Old clients can keep reading legacy `type`, `readAt`, `createdAt`.
- New clients should migrate to `canonical_type`, `category`, `priority`, and `data`.
