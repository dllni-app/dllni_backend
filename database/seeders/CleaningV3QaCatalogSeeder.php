<?php

declare(strict_types=1);

namespace Database\Seeders;

use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogReviewManifestService;
use Modules\Cleaning\Services\CleaningV3QaEnvironment;

final class CleaningV3QaCatalogSeeder extends Seeder
{
    public function run(CleaningV3QaEnvironment $environment, CleaningV3CatalogReviewManifestService $manifest): void
    {
        if ($environment->signingKey() === null) {
            throw new DomainException('V3_QA_ENVIRONMENT_REQUIRED');
        }

        $catalog = DB::transaction(function (): CleaningV3CatalogVersion {
            // Match the publication lock order; concurrent fixture runs serialize.
            if (DB::table('cleaning_v3_catalog_publication')->where('id', 1)->lockForUpdate()->first() === null) {
                throw new DomainException('V3_PUBLICATION_REGISTRY_UNAVAILABLE');
            }
            $existing = CleaningV3CatalogVersion::query()
                ->where('version_key', CleaningV3QaEnvironment::CATALOG_KEY)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $property = CleaningHomeType::query()->active()->forSection(CleaningHomeType::SECTION_PROPERTY)
                ->orderBy('id')->first();
            if ($property === null) {
                throw new DomainException('V3_QA_ACTIVE_PROPERTY_SOURCE_REQUIRED');
            }

            $catalog = new CleaningV3CatalogVersion([
                'title' => 'Cleaning V3 QA catalog',
                'notes' => 'Synthetic regular/deep tariffs for local manual cash admission testing.',
            ]);
            $catalog->version_key = CleaningV3QaEnvironment::CATALOG_KEY;
            $catalog->save();

            $item = $catalog->items()->create([
                'item_key' => 'qa.room', 'source_type' => 'property', 'source_id' => $property->id,
                'label_ar' => 'غرف الاختبار', 'label_en' => 'QA rooms',
                'measurement_mode' => 'count', 'required' => true, 'is_enabled' => true,
                'input_rules' => ['min' => 1, 'max' => 5, 'step' => 1],
            ]);
            // Mirrors the synthetic tariff used by the WP-01 contract fixture.
            foreach (['regular', 'deep'] as $mode) {
                $item->tariffRules()->create([
                    'rule_key' => $mode, 'cleaning_mode' => $mode, 'scope_key' => 'whole',
                    'quantity_min' => '1', 'quantity_max' => '6', 'unit_price' => '100000.00',
                    'unit_effort_minutes' => '30.00', 'is_enabled' => true,
                ]);
            }

            return $catalog;
        }, 3);

        $this->command?->line(json_encode([
            'catalogId' => $catalog->id,
            'catalogKey' => $catalog->version_key,
            'status' => $catalog->status,
            'reviewFingerprint' => $manifest->fingerprint($catalog),
        ], JSON_THROW_ON_ERROR));
    }
}
