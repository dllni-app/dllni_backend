# Phase 1 — REST / SM / Shared Commerce screen-state inventory
Date: 2026-11-05. Source: current Flutter feature paths and existing Pen frames. This is proposed design coverage; not a claim every Flutter screen is implemented.

## Navigation
Shared homepage (UNCHANGED) -> Restaurants or Supermarkets -> Home / Discover / Offers / Favorites -> Merchant -> Product -> Cart -> Checkout -> Confirmation -> Tracking. Deep links and order history must continue reaching the original merchant-specific context. Back returns to the previous search scope, query, filter and scroll position.

## Restaurant mappings
| ID | Screen | Current owner (source verified) | Required variants |
|---|---|---|---|
| REST-01 | Home | `rs_home/view/rs_home_screen.dart` | loading, partial error, nearby-empty, location-missing |
| REST-02 | Discovery | `rs_discover/view/rs_discover_screen.dart`, `rs_main_discover_view.dart` | merchants/products, query, filters, pagination, stale-response |
| REST-03 | Product search results | `rs_discover/view/rs_search_view_v2.dart` | results, empty, next-page-error |
| REST-04 | Offers | `rs_offers/view/rs_offers_screen.dart` | populated, empty, error |
| REST-05 | Favorites | `rs_favourite/view/rs_favourite_screen.dart` | merchants, products, guest, empty |
| REST-06 | Restaurant details | `rs_discover/view/rs_store_details_screen.dart` | open, closed, category, menu-empty, reviews |
| REST-07 | Meal details | `rs_discover/view/rs_product_details_screen.dart` | simple, required modifiers, out-of-stock, note |
| REST-08 | Cart | existing restaurant cart consumer; locate exact owner during Phase 3 | configured lines, empty, syncing, conflict |
| REST-09 | Checkout | existing restaurant checkout flow; preserve routes | delivery/pickup, schedule, coupon, quote ready/changed |
| REST-10 | Confirmation | existing restaurant order confirmation | successful only after server receipt |
| REST-11 | Tracking | existing restaurant order details/tracking | delivery/pickup, active/terminal, offline |
| REST-12 | Orders / reorder | current shared `orders` and restaurant consumers | historical detail, reorder preview |
| REST-13 | Group order | existing restaurant group order flow | invite, participants, combined review |
| REST-14 | Voting | existing restaurant group voting consumer | open, voted, closed |
| REST-15 | Lucky box | existing reachable restaurant consumer | eligibility, outcome |
| REST-16 | Merchant reviews | `rs_discover/view/rs_store_all_reviews_screen.dart` | available, empty |
| REST-17 | Recovery | proposed reusable commerce state UI | timeout, changed amount, offline, conflict |
| REST-18 | Filters | discovery filter sheet | provisional selection, apply, reset |

## Supermarket mappings
| ID | Screen | Current owner (source verified) | Required variants |
|---|---|---|---|
| SM-01 | Home | `sm_home/view/sm_home_screen.dart` | nearby, offers, missing location, partial error |
| SM-02 | Discovery | `sm_discover/view/sm_discover_screen.dart`, `sm_main_discover_view.dart` | stores/products, query, filters |
| SM-03 | Product results | `sm_discover/view/sm_search_view_v2.dart`, `sm_all_products_screen.dart` | result, empty, pagination error |
| SM-04 | Offers | `sm_offers/view/sm_offers_screen.dart` | offer, empty, invalid |
| SM-05 | Favorites | `sm_favorite/view/sm_favorite_screen.dart` | stores, products, empty |
| SM-06 | Store details | `sm_stores/view/sm_store_details_screen.dart` | categories, unavailable store |
| SM-07 | Product details | `sm_stores/view/sm_product_details_screen.dart` | stock, modifiers, notes, quantity |
| SM-08 | Alternatives | `sm_stores/view/widgets/supermarket_product_customization_section.dart` | no substitute, explicit alternative |
| SM-09 | Cart | `sm_cart/view/sm_cart_screen.dart` and details | store-specific, syncing, conflict |
| SM-10 | Checkout | `sm_cart/view/sm_cart_details_screen.dart` and late-time screen | delivery/pickup, date, coupon, quote |
| SM-11 | Confirmation | existing supermarket order-success consumer | success, retry lookup |
| SM-12 | Tracking | `sm_orders/view/sm_order_tracking_screen.dart` | delivery/pickup, terminal, reconnect |
| SM-13 | Orders / reorder | `sm_orders/view/sm_orders_screen.dart`, details | historical snapshot, reorder resolution |
| SM-14 | Comparison | `sm_stores` comparison entry point | comparable options, incompatible units |
| SM-15 | Shopping lists | `sm_stores/view/widgets/shopping_lists_dialog.dart` | list, save, add eligible selection |
| SM-16 | Filters | discovery filters | apply, reset, supported-only |
| SM-17 | Recovery | proposed reusable commerce state UI | stock changed, unavailable, quote expired |

## Shared state IDs and transitions
| ID | State | Trigger | Expected UI |
|---|---|---|---|
| SHARED-COMMERCE-01 | Address returned | existing selector selection | restore merchant and query; invalidate delivery quote |
| SHARED-COMMERCE-02 | Login returned | guest reaches authorized boundary | restore same merchant cart/checkout draft |
| SHARED-COMMERCE-03 | Cart syncing | mutation pending | keep visible count; disable checkout |
| SHARED-COMMERCE-04 | Cart conflict | revision mismatch | show authoritative lines and conflict |
| SHARED-COMMERCE-05 | Quote loading | checkout edit | retain draft; mark amount updating |
| SHARED-COMMERCE-06 | Quote ready | authoritative response | show expiry and payable breakdown |
| SHARED-COMMERCE-07 | Quote changed | server reprice | show differences; require confirmation |
| SHARED-COMMERCE-08 | Submitting | original idempotency key used | disable duplicate actions |
| SHARED-COMMERCE-09 | Recovery | timeout/unknown submit | query original receipt; never new key until resolved |
| SHARED-COMMERCE-10 | Tracking stale | offline/notification failure | show last confirmed status, freshness and retry |
| SHARED-COMMERCE-11 | Cache empty | no results | distinguish no location vs no matching merchants |
| SHARED-COMMERCE-12 | Permissions | denied address/notification | graceful continuation with available choices |

## Coverage and provenance limits
Pen: `designs/user-app-restaurants-code-export/alnadha-restaurants-all-screens-berry-v2.pen` has 20 top-level restaurant frames. Supermarket source: `designs/user-app-main-redesign/dllni-user-supermarket-redesign.pen` contains 34 top-level frames INCLUDING cleaning frames. The original Pen files are not altered. Nine Bee Order recordings are not present in the inspected workspace; no timestamped recording comparison is asserted. The Figma boards are editable screen compositions; secondary flow internals and exhaustive per-state artboards remain review work rather than completed detailed mockups.
