---
name: Shared Tables ERD
overview: Shared database tables used across Restaurant, Cleaning, and Supermarket modules. Lives in database/migrations/. Module-specific tables are defined in their module plan files.
todos:
  - id: create-tier1-migrations
    content: "Create Tier 1 shared migrations: roles, permissions, permission_role, cancellation_policies"
    status: pending
  - id: create-tier2-worker-migrations
    content: "Create Tier 2 shared worker migrations for Cleaning: workers, worker_zones, worker_availability, worker_trust_logs, property_type_configs, service_addons, travel_cost_configs"
    status: pending
  - id: create-tier3-catalog-migrations
    content: "Create Tier 3 shared catalog migrations: master_products, master_product_aliases, recipes, recipe_ingredients"
    status: pending
  - id: create-tier4-booking-protocol-migrations
    content: "Create Tier 4 shared booking protocol migrations: booking_reviews, booking_status_logs, booking_security_codes, booking_extensions, disputes, dispute_messages, sos_alerts, system_alerts"
    status: pending
  - id: create-worker-customer-rating-migration
    content: Create worker_customer_ratings migration for bidirectional customer-worker ratings
    status: pending
  - id: create-shared-enums
    content: Create/update shared enums in app/Enums (including MasterProductUnit, DocumentVerificationStatus, WorkerCustomerRatingType)
    status: pending
isProject: false
---

# Shared Tables ERD Plan

## Purpose

This file defines shared tables used by multiple modules. Module-specific tables live only in:

- `restaurant_system_erd_c43e2031.plan.md`
- `cleaning_service_erd_231c9672.plan.md`
- `supermarket_system_erd.plan.md`

## Excluded Scope

The following are intentionally excluded from active ERD coverage:

- delivery flows and delivery dispatch tables
- wallet and wallet ledger tables
- social integration features
- heatmap-specific analytics schemas

## Tier 1 - Global shared tables


| Table                    | Notes                                                            |
| ------------------------ | ---------------------------------------------------------------- |
| `users`                  | Existing Laravel user table                                      |
| `media`                  | Spatie MediaLibrary polymorphic table                            |
| `notifications`          | Laravel notifications table                                      |
| `personal_access_tokens` | Sanctum tokens                                                   |
| `roles`                  | Platform-level RBAC roles (`is_system` flag)                     |
| `permissions`            | Global permissions                                               |
| `permission_role`        | Pivot between `roles` and `permissions`                          |
| `cancellation_policies`  | Module-scoped policies (`restaurant`, `cleaning`, `supermarket`) |


## Tier 2 - Shared worker-service tables (Cleaning domain)


| Table                   | Notes                                        |
| ----------------------- | -------------------------------------------- |
| `workers`               | Worker profile and KPIs                      |
| `worker_zones`          | Preferred work areas (polygon support)       |
| `worker_availability`   | Availability calendar                        |
| `worker_trust_logs`     | Trust score audit log                        |
| `property_type_configs` | Guided-estimation lookup                     |
| `service_addons`        | Shared add-ons for cleaning-related bookings |
| `travel_cost_configs`   | Travel compensation rules                    |


## Tier 3 - Shared catalog and recipe tables (Restaurant + Supermarket)


| Table                    | Notes                                                |
| ------------------------ | ---------------------------------------------------- |
| `master_products`        | Canonical product catalog (barcode, unit, metadata)  |
| `master_product_aliases` | Alternate names/search aliases for `master_products` |
| `recipes`                | Recipe header entities                               |
| `recipe_ingredients`     | Recipe to product mapping with quantity/unit         |


## Tier 4 - Shared booking protocol tables (Cleaning)


| Table                    | Notes                                  |
| ------------------------ | -------------------------------------- |
| `booking_reviews`        | Polymorphic booking reviews            |
| `booking_status_logs`    | Polymorphic booking status transitions |
| `booking_security_codes` | Polymorphic mutual security codes      |
| `booking_extensions`     | Polymorphic extension requests         |
| `disputes`               | Polymorphic dispute headers            |
| `dispute_messages`       | Dispute thread messages                |
| `sos_alerts`             | Polymorphic emergency alerts           |
| `system_alerts`          | Polymorphic system anomaly alerts      |


## Tier 5 - Shared bidirectional rating table


| Table                     | Notes                                                                    |
| ------------------------- | ------------------------------------------------------------------------ |
| `worker_customer_ratings` | Worker rates customer and customer rates worker; tied to booking context |


## ERD Diagram (shared entities)

```mermaid
erDiagram
    users ||--o{ workers : "has worker profile"
    roles ||--o{ permission_role : "has"
    permissions ||--o{ permission_role : "in"

    master_products ||--o{ master_product_aliases : "has"
    recipes ||--o{ recipe_ingredients : "contains"
    master_products ||--o{ recipe_ingredients : "used in"

    workers ||--o{ worker_zones : "serves"
    workers ||--o{ worker_availability : "schedules"
    workers ||--o{ worker_trust_logs : "tracked by"

    users ||--o{ worker_customer_ratings : "rates or is rated"
    workers ||--o{ worker_customer_ratings : "rates or is rated"

    booking_reviews ||--o{ disputes : "may open"
    disputes ||--o{ dispute_messages : "has"

    workers {
        bigint id PK
        bigint user_id FK
        string first_name
        text bio
        decimal average_rating
        int total_completed_jobs
        int trust_score
        decimal acceptance_rate
        decimal cancellation_rate
        int open_disputes_count
        boolean is_active
        boolean is_suspended
        datetime suspended_until
        string home_address
        decimal home_latitude
        decimal home_longitude
        json default_working_hours
        timestamps created_at
    }

    master_products {
        bigint id PK
        string name
        string barcode UK
        string unit "enum MasterProductUnit"
        string brand "nullable"
        text description "nullable"
        boolean is_active
        timestamps created_at
    }

    recipes {
        bigint id PK
        string name
        string slug UK
        text description "nullable"
        int servings "nullable"
        boolean is_active
        timestamps created_at
    }

    recipe_ingredients {
        bigint id PK
        bigint recipe_id FK
        bigint master_product_id FK
        decimal quantity
        string unit "enum MasterProductUnit"
        boolean is_optional
    }

    worker_customer_ratings {
        bigint id PK
        bigint booking_id FK
        string booking_type "polymorphic"
        bigint worker_id FK
        bigint customer_id FK
        string rating_type "enum WorkerCustomerRatingType"
        tinyint rating
        text comment "nullable"
        timestamps created_at
    }
```



## Shared Enums (`app/Enums/`)


| Enum                         | Cases                                                                                                                                                |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PropertyType`               | `Studio`, `Apartment`, `Villa`, `Office`                                                                                                             |
| `LivingRoomSize`             | `Small`, `Medium`, `Large`                                                                                                                           |
| `GenderPreference`           | `Male`, `Female`, `Any`                                                                                                                              |
| `PermissionGroup`            | `Orders`, `Products`, `Inventory`, `Offers`, `Staff`, `Reports`, `Settings`, `Bookings`, `Workers`, `Disputes`, `SystemAlerts`, `Pricing`, `Catalog` |
| `AvailabilityType`           | `Available`, `Blocked`, `Vacation`                                                                                                                   |
| `ExtensionStatus`            | `Pending`, `Approved`, `Rejected`                                                                                                                    |
| `DisputeCategory`            | `PoorQuality`, `PropertyDamage`, `Unprofessional`, `BillingIssue`, `Other`                                                                           |
| `DisputeStatus`              | `Open`, `UnderReview`, `Resolved`, `Closed`                                                                                                          |
| `DisputeResolution`          | `FullRefund`, `PartialRefund`, `WorkerPenalty`, `Dismissed`                                                                                          |
| `EmergencyType`              | `SafetyThreat`, `MedicalEmergency`, `SevereConflict`                                                                                                 |
| `SOSStatus`                  | `Triggered`, `Acknowledged`, `Resolved`                                                                                                              |
| `AlertType`                  | `DelayedRating`, `FrozenGPS`, `SOSTriggered`, `TimeExpired`, `OverdueCompletion`, `AnomalyDetected`                                                  |
| `AlertSeverity`              | `Low`, `Medium`, `High`, `Critical`                                                                                                                  |
| `SystemAlertStatus`          | `New`, `Acknowledged`, `Resolved`                                                                                                                    |
| `DistanceMode`               | `CurrentLocation`, `HomeAddress`, `SmartAutomatic`                                                                                                   |
| `MasterProductUnit`          | `Piece`, `Gram`, `Kilogram`, `Milliliter`, `Liter`, `Pack`                                                                                           |
| `DocumentVerificationStatus` | `Pending`, `Approved`, `Rejected`                                                                                                                    |
| `WorkerCustomerRatingType`   | `WorkerToCustomer`, `CustomerToWorker`                                                                                                               |


## Polymorphic `booking_type` morph map


| Alias              | Model                                        |
| ------------------ | -------------------------------------------- |
| `cleaning_booking` | `Modules\\Cleaning\\Models\\CleaningBooking` |
| `event_booking`    | `Modules\\Cleaning\\Models\\EventBooking`    |


## Key Indexes (shared)

- `roles`: unique on `slug`, index on `is_system`
- `permissions`: unique on `slug`
- `permission_role`: unique on `permission_id` + `role_id`
- `cancellation_policies`: index on `module`, `is_active`, `is_default`
- `workers`: unique on `user_id`, index on `is_active`, `trust_score`
- `worker_zones`: index on `worker_id`, `is_active`
- `worker_availability`: index on `worker_id`, `availability_date`
- `worker_trust_logs`: index on `worker_id`, `created_at`
- `master_products`: unique on `barcode`, index on `name`
- `master_product_aliases`: index on `master_product_id`, `alias`
- `recipes`: unique on `slug`, index on `is_active`
- `recipe_ingredients`: index on `recipe_id`, `master_product_id`
- `booking_reviews`: unique on `booking_id` + `booking_type` + `customer_id`
- `booking_status_logs`: index on `booking_id`, `booking_type`
- `booking_security_codes`: index on `booking_id`, `booking_type`
- `booking_extensions`: index on `booking_id`, `booking_type`, `status`
- `disputes`: unique on `ticket_number`, index on `booking_id`, `booking_type`, `status`
- `dispute_messages`: index on `dispute_id`, `created_at`
- `sos_alerts`: index on `booking_id`, `booking_type`, `status`
- `system_alerts`: index on `booking_type`, `status`, `alert_type`
- `worker_customer_ratings`: unique on `booking_id` + `booking_type` + `rating_type`

## Module dependency summary

- **Restaurant module:** uses `users`, RBAC shared tables, cancellation policies, shared catalog/recipe tables.
- **Supermarket module:** uses `users`, RBAC shared tables, cancellation policies, shared catalog/recipe tables.
- **Cleaning module:** uses `users`, cancellation policies, worker infrastructure, travel/add-on config, booking protocol tables, and `worker_customer_ratings`.

