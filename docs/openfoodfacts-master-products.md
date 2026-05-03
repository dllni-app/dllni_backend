# OpenFoodFacts Master Products Import

## Purpose
This command imports OpenFoodFacts products sold in Syria into `master_products`, updates OpenFoodFacts-derived fields on reimport, and optionally imports one primary product image per item.

## Command
```bash
php artisan supermarket:import-openfoodfacts-master-products {source?} \
  --country=en:syria \
  --limit= \
  --chunk=500 \
  --skip-images \
  --dry-run
```

## Source Handling
- `source` may be:
  - local `.jsonl`
  - local `.jsonl.gz`
  - remote URL
- If omitted, the command uses `OPENFOODFACTS_PRODUCTS_JSONL_URL`.
- Remote sources are downloaded to a temporary file first, then streamed line-by-line.

## Environment Variables
```dotenv
OPENFOODFACTS_PRODUCTS_JSONL_URL=https://static.openfoodfacts.org/data/openfoodfacts-products.jsonl.gz
OPENFOODFACTS_USER_AGENT="DllniBackend/1.0 (contact: backend@dllni.local)"
OPENFOODFACTS_TIMEOUT=120
OPENFOODFACTS_RETRY_TIMES=3
OPENFOODFACTS_RETRY_SLEEP=500
OPENFOODFACTS_IMAGE_MAX_BYTES=5242880
OPENFOODFACTS_IMAGE_SLEEP_MS=100
```

## Behavior
- Country filter default: `en:syria` from `countries_tags`.
- Valid rows require:
  - `code` (mapped to `barcode`)
  - a usable product name (Arabic name preferred, then default name fallbacks)
- Import upserts by `barcode`.
- Reimport refreshes OpenFoodFacts-derived fields:
  - `name`, `brand`, `description`, `unit`, `is_active`
  - `openfoodfacts_url`
  - `openfoodfacts_last_modified_at`
  - `openfoodfacts_imported_at`
  - `openfoodfacts_payload_hash`
  - `openfoodfacts_countries_tags`
- Existing local IDs and linked `sm_products` relations are preserved.

## Images
- One primary image is attempted per product unless `--skip-images` is used.
- Allowed MIME: `image/jpeg`, `image/png`, `image/webp`.
- Max image size is controlled by `OPENFOODFACTS_IMAGE_MAX_BYTES`.
- If a new image validates and saves, older master-product media entries are removed.
- If image download or validation fails, product data import still succeeds and existing media is preserved.

## Aliases
- Alternate names and brand+name variants are normalized and inserted into `master_product_aliases`.
- Aliases are trimmed, deduplicated case-insensitively, and canonical-name duplicates are skipped.

## Dry Run
- `--dry-run` computes and reports import stats without writing database rows or media.

## Command Output Stats
- scanned rows
- matched country rows
- created
- updated
- skipped missing barcode/name
- skipped unchanged hash
- image imported
- image failed
- JSON parse errors

## Attribution
OpenFoodFacts data and images are imported with source metadata. Image media custom properties include source attribution to OpenFoodFacts contributors (ODbL).

