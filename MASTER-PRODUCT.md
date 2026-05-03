# OpenFoodFacts Syrian Master Product Import

## Summary
Implement a backend-only Artisan import for OpenFoodFacts products sold in Syria. The import will populate the global `master_products` catalog, attach one primary image per product through the existing Spatie Media Library setup, and make imported catalog data usable from the supermarket store-owner product flow.

OpenFoodFacts integration will follow their current guidance: use a custom `User-Agent`, respect endpoint limits, prefer bulk data exports for large imports, and avoid downloading oversized images when a smaller selected image is enough. References: [API usage conditions](https://support.openfoodfacts.org/help/en-gb/12-api-data-reuse/94-are-there-conditions-to-use-the-api), [image download guide](https://openfoodfacts.github.io/openfoodfacts-server/api/how-to-download-images/), [OpenFoodFacts data page](https://world.openfoodfacts.org/data).

## Data Model And Public Interfaces
- Add a migration for `master_products`:
  - `barcode` as nullable unique `string(64)`, used as the OpenFoodFacts product code.
  - `openfoodfacts_url` nullable string.
  - `openfoodfacts_last_modified_at` nullable timestamp, mapped from OFF `last_modified_t`.
  - `openfoodfacts_imported_at` nullable timestamp.
  - `openfoodfacts_payload_hash` nullable `char(64)` with an index.
  - `openfoodfacts_countries_tags` nullable JSON for audit/debugging.
- Update `App\Models\MasterProduct`:
  - Add new fields to `$fillable`.
  - Cast OpenFoodFacts timestamps to datetime and countries tags to array.
  - Keep the existing `master-product-image` media collection and conversions.
- Update `Modules\Supermarket\Http\Resources\MasterProductResource`:
  - Return `barcode`, `openfoodfactsUrl`, and existing `primaryImage`.
- Add config in `config/services.php`:
  - `services.openfoodfacts.products_jsonl_url = env('OPENFOODFACTS_PRODUCTS_JSONL_URL', 'https://static.openfoodfacts.org/data/openfoodfacts-products.jsonl.gz')`
  - `services.openfoodfacts.user_agent = env('OPENFOODFACTS_USER_AGENT', 'DllniBackend/1.0 (contact: backend@dllni.local)')`
  - `timeout`, `retry_times`, `retry_sleep`, `image_max_bytes`, `image_sleep_ms`.

## Import Implementation
- Add an Artisan command in `routes/console.php` that delegates to a service:
  - `supermarket:import-openfoodfacts-master-products {source?} {--country=en:syria} {--limit=} {--chunk=500} {--skip-images} {--dry-run}`
  - `source` accepts a local `.jsonl`, local `.jsonl.gz`, or remote URL. If omitted, use configured JSONL export URL.
  - `--limit` limits imported Syrian products after country filtering.
  - `--dry-run` reads and maps data, reports stats, but does not write database rows or media.
- Add `Modules\Supermarket\Services\OpenFoodFactsMasterProductImportService`:
  - Streams JSONL line by line using `gzopen` for `.gz` and `SplFileObject` for plain JSONL.
  - Downloads remote source to a temp file first using Laravel HTTP with the configured `User-Agent`.
  - Filters records where `countries_tags` contains the selected country, default `en:syria`.
  - Skips records missing `code` or a usable product name.
  - Upserts by `barcode`.
  - Refresh policy is “refresh OpenFoodFacts fields”: update OFF-derived fields on every reimport while preserving local `id`, relations, and existing store products.
- Add a mapper class or private mapper methods:
  - `barcode`: OFF `code`.
  - `name`: first non-empty `product_name_ar`, `product_name`, localized product name, then `generic_name`.
  - `brand`: `brands`, trimmed to 255 chars.
  - `description`: first non-empty `generic_name_ar`, `generic_name`, or a compact value built from name plus `quantity`.
  - `unit`: map `g` to `gram`, `kg` to `kilogram`, `ml` to `milliliter`, `l` to `liter`, piece-like units to `piece`, package/container units to `pack`, and unknown/missing units to `pack`.
  - `is_active`: always true for imported valid products.
  - `openfoodfacts_url`: `https://world.openfoodfacts.org/product/{barcode}`.
  - `openfoodfacts_payload_hash`: SHA-256 of the normalized mapped payload.
- Alias handling:
  - Use existing `master_product_aliases`.
  - Add aliases from alternate product/generic names and brand+name variants.
  - Trim, deduplicate case-insensitively, skip aliases equal to the canonical name, and use `firstOrCreate`.

## Image And Media Behavior
- Resolve one primary image URL per imported product:
  - Prefer `selected_images.front.display` in Arabic, English, then any available language.
  - Fall back to `selected_images.front.small`, `image_front_small_url`, `image_front_url`, `image_small_url`, then `image_url`.
  - Prefer 400/display-sized URLs over full-size URLs.
- Download image to a temp file before touching existing media.
- Validate:
  - HTTP success.
  - MIME is `image/jpeg`, `image/png`, or `image/webp`.
  - Size is at or below `services.openfoodfacts.image_max_bytes`, default 5 MB.
- Store with Spatie:
  - Collection: `MasterProduct::IMAGE_COLLECTION`.
  - File name: `off-{barcode}.{extension}`.
  - Custom properties: `source = openfoodfacts`, `barcode`, `source_url`, `image_url`, `imported_at`, and license/attribution note.
- Refresh policy:
  - On successful image download, replace the existing master product image collection.
  - If image download fails, keep the product row and preserve any existing media.
  - Respect `--skip-images` for data-only imports.
- Add a small configurable delay between image downloads to avoid hammering OpenFoodFacts image hosts.

## Supermarket Flow Integration
- Update store-owner master product search to match active products by:
  - name prefix,
  - alias prefix,
  - barcode prefix.
- Update `SmProductService::bulkCreateFromMasterProductIdsForStore`:
  - Copy `barcode` from the master product into the created `sm_products` row.
  - Keep price, discount, stock, expiration, and category defaults unchanged.
- Update `SmProductResource` image response:
  - Prefer store product media from `SmProduct::IMAGE_COLLECTION`.
  - If no store product media exists and `masterProduct.media` is loaded, fall back to the linked master product image URL.
  - Do not duplicate master product media into every store product.

## Reporting And Failure Handling
- Command output reports:
  - scanned rows,
  - matched Syria rows,
  - created,
  - updated,
  - skipped missing barcode/name,
  - skipped unchanged hash,
  - image imported,
  - image failed,
  - JSON parse errors.
- Invalid individual rows never abort the full import.
- Fatal source errors, such as inaccessible source file or failed remote source download, return a non-zero command exit code.
- Add `docs/openfoodfacts-master-products.md` documenting command usage, env vars, source attribution, and reimport behavior.

## Test Plan
- Mapper tests:
  - country filter accepts `en:syria`.
  - unit mapping covers grams, kilograms, milliliters, liters, pieces, packs, and unknown values.
  - name fallback prefers Arabic name, then default product name.
- Command/import tests:
  - Imports a JSONL fixture with one Syrian product and skips a non-Syrian product.
  - Creates `master_products` with barcode, brand, unit, description, OFF URL, timestamps, and countries tags.
  - Reimport with same barcode refreshes OFF fields instead of creating duplicates.
  - `--dry-run` reports intended work without database/media changes.
  - Bad JSON lines and missing barcode/name are counted and skipped.
- Media tests:
  - `Http::fake` supplies a valid image and verifies Spatie media is stored in `master-product-image`.
  - Failed image download does not fail product import.
  - Unsupported MIME and oversized image are skipped.
  - Reimport replaces old OFF image only after the new image validates.
- Supermarket API tests:
  - Store-owner master search finds imported products by name, alias, and barcode.
  - Creating store products from imported master products copies barcode.
  - Store product response falls back to master product image when the store product has no image.
- Regression tests:
  - Existing manual master products without barcode remain valid.
  - Existing store products linked to master products keep working after migration.

## Assumptions
- “Syrian products” means OpenFoodFacts records tagged as sold in Syria: `countries_tags` includes `en:syria`.
- The first version is command-only, with no Filament import button.
- Reimport refreshes OpenFoodFacts-derived fields and image when available.
- Only one primary product image is imported per master product in v1.
