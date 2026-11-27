<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Administrative approval of an immutable catalog, independently of
 * collection or funding. Publication never authorizes booking or settlement.
 */
final class CleaningV3AdminCatalogPublication
{
    public function __construct(private readonly CleaningV3CatalogReviewManifestService $manifest,
        private readonly CleaningV3CatalogDraftValidator $validator) {}

    public static function authorized(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['admin', 'Super Admin']);
    }

    public static function digest(): ?string
    {
        if (app(CleaningV3QaEnvironment::class)->signingKey() !== null) {
            return self::verifiedQaDigest();
        }

        return self::commercialDigest();
    }

    public static function qaDigest(): ?string
    {
        return app(CleaningV3QaEnvironment::class)->signingKey() === null
            ? null : self::verifiedQaDigest();
    }

    private static function verifiedQaDigest(): ?string
    {
        if (! Schema::hasTable('cleaning_v3_catalog_versions')) {
            return null;
        }

        $version = CleaningV3CatalogVersion::query()
            ->where('version_key', CleaningV3QaEnvironment::CATALOG_KEY)
            ->where('status', CleaningV3CatalogVersion::PUBLISHED)->whereNotNull('published_at')->first();

        return self::verifiedDigest($version);
    }

    public static function commercialDigest(): ?string
    {
        if (! config('cleaning_v3_catalog.admin_publication_enabled', false)) {
            $digest = config('cleaning_v3_catalog.approved_publication_digest');
            if (! is_string($digest) || (Schema::hasTable('cleaning_v3_catalog_versions')
                && CleaningV3CatalogVersion::query()->where('version_key', CleaningV3QaEnvironment::CATALOG_KEY)
                    ->where('published_digest', $digest)->exists())) {
                return null;
            }

            return $digest;
        }
        if (! Schema::hasTable('cleaning_v3_catalog_publication')) {
            return null;
        }
        $pointer = DB::table('cleaning_v3_catalog_publication')->where('id', 1)->first();
        $version = $pointer?->catalog_version_id === null ? null : CleaningV3CatalogVersion::query()
            ->whereKey($pointer->catalog_version_id)->where('status', 'published')->whereNotNull('published_at')->first();
        if ($version === null || $version->version_key === CleaningV3QaEnvironment::CATALOG_KEY
            || ! is_string($version->published_digest)
            || ! hash_equals($version->published_digest, (string) $pointer->digest)) {
            return null;
        }

        return self::verifiedDigest($version);
    }

    private static function verifiedDigest(?CleaningV3CatalogVersion $version): ?string
    {
        if ($version === null || ! is_string($version->published_digest)) {
            return null;
        }
        // Detect bypasses of model immutability and concurrent child edits.
        $actual = app(CleaningV3CatalogReviewManifestService::class)->fingerprint($version);
        if (! hash_equals($actual, $version->published_digest)) {
            return null;
        }
        // A source disabled in the existing catalog must not continue to be
        // offered to new customers just because the immutable V3 hash matches.
        foreach ($version->items->where('is_enabled', true) as $item) {
            try {
                app(CleaningV3CatalogDraftValidator::class)->assertActiveSource($item->source_type, (int) $item->source_id);
            } catch (ValidationException) {
                return null;
            }
        }
        return $actual;
    }

    /** Structural coverage for supported, enabled fields. No funding claims. */
    public function issues(CleaningV3CatalogVersion $version): array
    {
        $issues = array_values(array_diff($this->validator->review($version), [
            'APPROVED_CATALOG_COVERAGE_MATRIX_MISSING', 'APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING',
        ]));
        $version->load('items.tariffRules', 'items.presets');
        $enabled = $version->items->where('is_enabled', true);
        if ($enabled->count() > 100) {
            $issues[] = 'CATALOG_FIELD_LIMIT_EXCEEDED';
        }
        if ($enabled->isEmpty()) {
            $issues[] = 'CATALOG_EMPTY';
        }
        $fields = collect(app(CleaningV3CatalogPublicSerializer::class)->serialize($version, 'ar')['items'])->keyBy('key');
        if ($enabled->whereNotIn('source_type', ['equipment', 'material'])->isEmpty()) {
            $issues[] = 'CATALOG_NO_BOOKABLE_SERVICE';
        }
        $selector = app(CleaningV3PropertyFormSelector::class);
        $propertyKeys = $fields->contains(static fn ($field) => $field['propertyScopeConfigured'])
            ? $fields->where('sourceType', 'property')->pluck('key')->all() : [null];
        if ($selector->issues($fields->values()->all()) === []) {
            foreach ($propertyKeys as $propertyKey) {
                $visible = collect($selector->select($fields->values()->all(), $propertyKey));
                // A timed event cannot also require ordinary priced task
                // fields; the event pricing engine intentionally rejects them.
                if ($visible->contains('sourceType', 'event_type')
                    && $visible->contains(static fn ($f) => $f['required']
                        && ! in_array($f['sourceType'], ['event_type', 'equipment'], true))) {
                    $issues[] = 'EVENT_FORM_REQUIRES_TASK_FIELDS:'.($propertyKey ?? 'shared');
                }
                if (! $visible->contains(static fn ($f) => ! in_array($f['sourceType'], ['equipment', 'material'], true))) {
                    $issues[] = 'CATALOG_NO_BOOKABLE_SERVICE:'.($propertyKey ?? 'shared');
                }
            }
        }
        foreach ($enabled as $item) {
            $field = $fields->get($item->item_key);
            $key = $item->item_key;
            if (! ($field['supportedByServer'] ?? false)) {
                $issues[] = 'UNSUPPORTED_INPUT_RULES:'.$key;
                continue;
            }
            if ($item->source_type === 'equipment') {
                continue;
            }
            $rules = $item->tariffRules->where('is_enabled', true);
            $input = $item->input_rules ?? [];
            if ($item->source_type === 'event_type') {
                // Event schedule accepts 1..20 workers, half-hour steps,
                // 60 minutes through a same-day maximum of 1410 minutes.
                $min = $input['min'] ?? 1;
                $max = $input['max'] ?? 470;
                if ($item->required) {
                    $issues[] = 'EVENT_TYPE_MUST_BE_OPTIONAL:'.$key;
                }
                if (! is_numeric($min) || ! is_numeric($max) || (float) $min > 1
                    || (float) $max < 470 || (isset($input['step']) && (float) $input['step'] !== 0.5)) {
                    $issues[] = 'EVENT_COVERAGE_INCOMPLETE:'.$key;
                }
                $groups = ['regular|whole'];
            } else {
                if (! in_array($field['componentId'], ['numeric', 'stepper'], true)) {
                    $issues[] = 'UNSUPPORTED_PRICED_COMPONENT:'.$key;
                    continue;
                }
                $min = $input['min'] ?? null;
                $max = $input['max'] ?? null;
                if (! is_numeric($min) || ! is_numeric($max) || (float) $min <= 0
                    || (float) $max < (float) $min || (float) $max > 9999999999) {
                    $issues[] = 'FINITE_QUANTITY_RANGE_REQUIRED:'.$key;
                    continue;
                }
                if ($item->measurement_mode === 'fixed' && ((float) $min !== 1.0 || (float) $max !== 1.0)) {
                    $issues[] = 'FIXED_QUANTITY_REQUIRED:'.$key;
                }
                if (in_array($item->measurement_mode, ['count', 'piece', 'fixed'], true)
                    && (floor((float) $min) !== (float) $min || floor((float) $max) !== (float) $max
                        || (isset($input['step']) && floor((float) $input['step']) !== (float) $input['step']))) {
                    $issues[] = 'INTEGER_QUANTITY_REQUIRED:'.$key;
                }
                if (in_array($item->measurement_mode, ['count', 'piece'], true) && (float) $max > 40) {
                    $issues[] = 'TASK_QUANTITY_LIMIT_EXCEEDED:'.$key;
                }
                $groups = ['regular|whole', 'deep|whole'];
                foreach ($field['deepScopeChoices'] as $scope) {
                    $groups[] = 'deep|'.$scope['key'];
                }
            }
            foreach (array_unique($groups) as $group) {
                [$mode, $scope] = explode('|', $group);
                $tiers = $rules->where('cleaning_mode', $mode)->where('scope_key', $scope);
                if ($tiers->isEmpty() || (float) $tiers->min('quantity_min') > (float) $min
                    || (float) $tiers->max('quantity_max') <= (float) $max) {
                    $issues[] = 'TARIFF_COVERAGE_INCOMPLETE:'.$key.':'.$group;
                }
            }
        }
        return array_values(array_unique($issues));
    }

    public function publish(CleaningV3CatalogVersion $draft, string $reviewedFingerprint): CleaningV3CatalogVersion
    {
        abort_unless(self::authorized(), 403);
        if (! config('cleaning_v3_catalog.admin_publication_enabled', false)) {
            throw new DomainException('V3_ADMIN_PUBLICATION_PAUSED');
        }
        return DB::transaction(function () use ($draft, $reviewedFingerprint) {
            $pointer = DB::table('cleaning_v3_catalog_publication')->where('id', 1)->lockForUpdate()->first();
            if ($pointer === null) {
                throw new DomainException('V3_PUBLICATION_REGISTRY_UNAVAILABLE');
            }
            $version = CleaningV3CatalogVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $isQa = $version->version_key === CleaningV3QaEnvironment::CATALOG_KEY;
            if ($isQa !== (app(CleaningV3QaEnvironment::class)->signingKey() !== null)) {
                throw new DomainException('V3_CATALOG_PUBLICATION_ENVIRONMENT_MISMATCH');
            }
            $version->assertEditable();
            $items = $version->items()->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->presets()->lockForUpdate()->get();
                $item->tariffRules()->lockForUpdate()->get();
            }
            $actual = $this->manifest->fingerprint($version);
            if (! hash_equals($actual, $reviewedFingerprint)) {
                throw new DomainException('V3_CATALOG_REVIEW_CHANGED');
            }
            $issues = $this->issues($version);
            if ($issues !== []) {
                throw ValidationException::withMessages(['catalog' => $issues]);
            }
            $now = now();
            // Direct, guarded state transition; normal model edits stay blocked.
            DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)->where('status', 'draft')->update([
                'status' => 'published', 'published_by' => auth()->id(),
                'published_at' => $now, 'published_digest' => $actual, 'updated_at' => $now,
            ]);
            if (! $isQa) {
                DB::table('cleaning_v3_catalog_publication')->where('id', 1)->update([
                    'catalog_version_id' => $version->id, 'digest' => $actual, 'updated_at' => $now,
                ]);
            }
            return $version->fresh();
        }, 3);
    }

    public function duplicate(CleaningV3CatalogVersion $source): CleaningV3CatalogVersion
    {
        abort_unless(self::authorized(), 403);
        return DB::transaction(function () use ($source) {
            $source = CleaningV3CatalogVersion::query()->whereKey($source->id)->where('status', 'published')->firstOrFail();
            if ($source->version_key === CleaningV3QaEnvironment::CATALOG_KEY) {
                throw new DomainException('V3_QA_CATALOG_DUPLICATION_UNAVAILABLE');
            }
            $source->load('items.presets', 'items.tariffRules');
            $draft = CleaningV3CatalogVersion::query()->create(['title' => mb_substr($source->title.' - نسخة جديدة', 0, 180), 'notes' => $source->notes]);
            foreach ($source->items as $item) {
                $copy = $draft->items()->create($item->only(['item_key', 'source_type', 'source_id', 'label_ar', 'label_en', 'measurement_mode', 'input_rules', 'required', 'is_enabled', 'sort_order']));
                foreach ($item->presets as $preset) {
                    $copy->presets()->create($preset->only(['preset_key', 'label_ar', 'label_en', 'minimum_value', 'maximum_value', 'sort_order']));
                }
                foreach ($item->tariffRules as $rule) {
                    $copy->tariffRules()->create($rule->only(['rule_key', 'cleaning_mode', 'scope_key', 'quantity_min', 'quantity_max', 'unit_price', 'unit_effort_minutes', 'is_enabled', 'sort_order']));
                }
            }
            return $draft;
        }, 3);
    }
}
