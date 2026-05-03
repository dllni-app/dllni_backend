<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasBarcode = Schema::hasColumn('master_products', 'barcode');
        $hasOpenFoodFactsUrl = Schema::hasColumn('master_products', 'openfoodfacts_url');
        $hasOpenFoodFactsLastModifiedAt = Schema::hasColumn('master_products', 'openfoodfacts_last_modified_at');
        $hasOpenFoodFactsImportedAt = Schema::hasColumn('master_products', 'openfoodfacts_imported_at');
        $hasOpenFoodFactsPayloadHash = Schema::hasColumn('master_products', 'openfoodfacts_payload_hash');
        $hasOpenFoodFactsCountriesTags = Schema::hasColumn('master_products', 'openfoodfacts_countries_tags');

        if (
            $hasBarcode
            && $hasOpenFoodFactsUrl
            && $hasOpenFoodFactsLastModifiedAt
            && $hasOpenFoodFactsImportedAt
            && $hasOpenFoodFactsPayloadHash
            && $hasOpenFoodFactsCountriesTags
        ) {
            return;
        }

        Schema::table('master_products', function (Blueprint $table) use (
            $hasBarcode,
            $hasOpenFoodFactsUrl,
            $hasOpenFoodFactsLastModifiedAt,
            $hasOpenFoodFactsImportedAt,
            $hasOpenFoodFactsPayloadHash,
            $hasOpenFoodFactsCountriesTags
        ): void {
            if (! $hasBarcode) {
                $table->string('barcode', 64)->nullable()->unique();
            }

            if (! $hasOpenFoodFactsUrl) {
                $table->string('openfoodfacts_url')->nullable();
            }

            if (! $hasOpenFoodFactsLastModifiedAt) {
                $table->timestamp('openfoodfacts_last_modified_at')->nullable();
            }

            if (! $hasOpenFoodFactsImportedAt) {
                $table->timestamp('openfoodfacts_imported_at')->nullable();
            }

            if (! $hasOpenFoodFactsPayloadHash) {
                $table->char('openfoodfacts_payload_hash', 64)->nullable()->index('master_products_off_payload_hash_idx');
            }

            if (! $hasOpenFoodFactsCountriesTags) {
                $table->json('openfoodfacts_countries_tags')->nullable();
            }
        });
    }

    public function down(): void
    {
        $hasBarcode = Schema::hasColumn('master_products', 'barcode');
        $hasOpenFoodFactsUrl = Schema::hasColumn('master_products', 'openfoodfacts_url');
        $hasOpenFoodFactsLastModifiedAt = Schema::hasColumn('master_products', 'openfoodfacts_last_modified_at');
        $hasOpenFoodFactsImportedAt = Schema::hasColumn('master_products', 'openfoodfacts_imported_at');
        $hasOpenFoodFactsPayloadHash = Schema::hasColumn('master_products', 'openfoodfacts_payload_hash');
        $hasOpenFoodFactsCountriesTags = Schema::hasColumn('master_products', 'openfoodfacts_countries_tags');

        if (
            ! $hasBarcode
            && ! $hasOpenFoodFactsUrl
            && ! $hasOpenFoodFactsLastModifiedAt
            && ! $hasOpenFoodFactsImportedAt
            && ! $hasOpenFoodFactsPayloadHash
            && ! $hasOpenFoodFactsCountriesTags
        ) {
            return;
        }

        Schema::table('master_products', function (Blueprint $table) use (
            $hasBarcode,
            $hasOpenFoodFactsUrl,
            $hasOpenFoodFactsLastModifiedAt,
            $hasOpenFoodFactsImportedAt,
            $hasOpenFoodFactsPayloadHash,
            $hasOpenFoodFactsCountriesTags
        ): void {
            if ($hasBarcode) {
                $table->dropColumn('barcode');
            }

            if ($hasOpenFoodFactsUrl) {
                $table->dropColumn('openfoodfacts_url');
            }

            if ($hasOpenFoodFactsLastModifiedAt) {
                $table->dropColumn('openfoodfacts_last_modified_at');
            }

            if ($hasOpenFoodFactsImportedAt) {
                $table->dropColumn('openfoodfacts_imported_at');
            }

            if ($hasOpenFoodFactsPayloadHash) {
                $table->dropColumn('openfoodfacts_payload_hash');
            }

            if ($hasOpenFoodFactsCountriesTags) {
                $table->dropColumn('openfoodfacts_countries_tags');
            }
        });
    }
};

