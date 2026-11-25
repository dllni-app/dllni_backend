<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3AdminCatalogPublication;
use Modules\Cleaning\Services\CleaningV3CatalogReviewManifestService;
use Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function adminCashCatalogFixture(): array
{
    config()->set([
        'cleaning_v3_catalog.admin_publication_enabled' => true,
        'cleaning_v3_catalog.read_enabled' => true,
        'cleaning_v3_catalog.cash_quote_enabled' => true,
        'cleaning_v3_catalog.new_quote_archive_enabled' => true,
    ]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    test()->actingAs($admin);
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY, 'code' => 'qa_admin_cash',
        'booking_value' => 'qa_admin_cash', 'title' => 'QA property', 'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'Cash catalog']);
    $item = $version->items()->create([
        'item_key' => 'qa.room', 'source_type' => 'property', 'source_id' => $source->id,
        'label_ar' => 'QA room', 'measurement_mode' => 'count', 'required' => true,
        'input_rules' => ['min' => 1, 'max' => 5, 'step' => 1],
    ]);
    foreach (['regular', 'deep'] as $mode) {
        $item->tariffRules()->create([
            'rule_key' => $mode, 'cleaning_mode' => $mode, 'scope_key' => 'whole',
            'quantity_min' => '1', 'quantity_max' => '6', 'unit_price' => '100000.00',
            'unit_effort_minutes' => '30.00', 'is_enabled' => true,
        ]);
    }
    return [$admin, $version, $item];
}

test('admin publishes the exact reviewed priced catalog and copies it without mutating history', function (): void {
    [$admin, $draft, $item] = adminCashCatalogFixture();
    $publication = app(CleaningV3AdminCatalogPublication::class);
    $manifest = app(CleaningV3CatalogReviewManifestService::class);
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertStatus(503);
    $oldHash = $manifest->fingerprint($draft);
    $item->tariffRules()->first()->update(['unit_price' => '110000.00']);
    expect(fn () => $publication->publish($draft, $oldHash))->toThrow(DomainException::class, 'V3_CATALOG_REVIEW_CHANGED');
    $hash = $manifest->fingerprint($draft);
    $published = $publication->publish($draft, $hash);
    expect($published->status)->toBe('published')
        ->and((int) $published->published_by)->toBe($admin->id)
        ->and(CleaningV3AdminCatalogPublication::digest())->toBe($hash);
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertOk()
        ->assertJsonPath('data.catalogRevision', $hash)
        ->assertJsonPath('data.capabilities.createEnabled', false);
    expect(fn () => $item->update(['label_ar' => 'tampered']))->toThrow(LogicException::class);
    $copy = $publication->duplicate($published);
    expect($copy->status)->toBe('draft')->and($copy->items()->count())->toBe(1);
    $copy->items()->first()->tariffRules()->first()->update(['unit_price' => '120000.00']);
    expect($published->items()->first()->tariffRules()->first()->unit_price)->toBe('110000.00');
    $newHash = $manifest->fingerprint($copy);
    $publication->publish($copy, $newHash);
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertOk()->assertJsonPath('data.catalogRevision', $newHash);
    expect($published->fresh()->published_digest)->toBe($hash);
    // Raw SQL corruption must fail closed, even if the activation pointer is intact.
    DB::table('cleaning_v3_catalog_items')->where('catalog_version_id', $copy->id)->update(['label_ar' => 'tampered']);
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertStatus(503);
});

test('incomplete pricing and non-administrators cannot publish', function (): void {
    [, $draft, $item] = adminCashCatalogFixture();
    $publication = app(CleaningV3AdminCatalogPublication::class);
    $manifest = app(CleaningV3CatalogReviewManifestService::class);
    $item->tariffRules()->where('cleaning_mode', 'deep')->first()->delete();
    expect(fn () => $publication->publish($draft, $manifest->fingerprint($draft)))->toThrow(ValidationException::class);
    expect($draft->fresh()->status)->toBe('draft');
    $this->actingAs(User::factory()->create());
    expect(fn () => $publication->publish($draft, $manifest->fingerprint($draft)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('published cash quote is signed unpaid and replays without creating funding or booking', function (): void {
    [, $draft] = adminCashCatalogFixture();
    $publication = app(CleaningV3AdminCatalogPublication::class);
    $manifest = app(CleaningV3CatalogReviewManifestService::class);
    $version = $publication->publish($draft, $manifest->fingerprint($draft));
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $customer->id, 'street' => 'QA street', 'latitude' => '36.20', 'longitude' => '37.15',
    ]);
    $revision = $this->getJson('/api/v1/cleaning/v3/customer-preferences')->assertOk()->json('data.addresses.0.revision');
    DB::table('cleaning_financial_settings')->delete();
    \App\Models\CleaningFinancialSetting::query()->create([
        ...\Modules\Cleaning\Support\CleaningRuntimeSettings::financialDefaults(),
        'travel_markup_value' => 1000, 'cleaning_minimum_order_price' => 1000,
        'supervisor_hourly_rate' => 5000, 'extension_rate_per_30_minutes' => 10000,
    ]);
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $version->version_key,
        'catalogRevision' => $version->published_digest, 'cleaningMode' => 'regular',
        'answers' => ['qa.room' => 2], 'pricingPreviewRequested' => true,
        'preferences' => ['addressId' => $address->id, 'addressRevision' => $revision,
            'genderPreference' => 'any', 'excludedWorkerIds' => []],
        'appointments' => [['appointmentKey' => 'appointment', 'visits' => [[
            'occurrenceKey' => 'visit', 'scheduledDate' => now('Asia/Damascus')->addDay()->toDateString(),
            'scheduledTime' => '09:00', 'durationMinutes' => 120, 'requiredWorkers' => 1,
        ]]]],
    ];
    $preview = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)->assertOk();
    $form['expectedPricingFingerprint'] = $preview->json('data.commercialPricePreview.pricingFingerprint');
    $form['idempotencyKey'] = (string) \Illuminate\Support\Str::uuid();
    $url = '/api/v1/cleaning/v3/quotes';
    $bookings = DB::table('cleaning_bookings')->count();
    $result = $this->postJson($url, $form)->assertOk()
        ->assertJsonPath('data.paymentMethod', 'cash_on_delivery')
        ->assertJsonPath('data.paymentStatus', 'unpaid')
        ->assertJsonPath('data.snapshot.paymentStatus', 'unpaid')
        ->assertJsonPath('data.snapshot.totalWithAdditionalWorkerFees', '201000.00')
        ->assertJsonPath('data.canCharge', false)
        ->assertJsonPath('data.canCreateBooking', false);
    $envelope = array_intersect_key($result->json('data'), array_flip(['quoteId', 'customerId', 'issuedAt', 'expiresAt', 'snapshot', 'signature']));
    expect(app(CleaningV3SignedQuoteEnvelope::class)->verify($envelope, $customer->id, $version->version_key, $version->published_digest)['paymentMethod'])->toBe('cash_on_delivery');
    $this->postJson($url, $form)->assertOk()->assertJsonPath('data.quoteId', $result->json('data.quoteId'));
    $this->postJson($url, [...$form, 'paymentStatus' => 'paid'])->assertStatus(422);
    DB::table('cleaning_financial_settings')->update(['travel_markup_value' => 2000]);
    $this->postJson($url, $form)->assertStatus(409)->assertJsonPath('code', 'REQUOTE_REQUIRED');
    $this->postJson('/api/v1/cleaning/v3/bookings', $form)->assertStatus(503);
    expect(DB::table('cleaning_booking_quotes')->count())->toBe(1)
        ->and(DB::table('cleaning_bookings')->count())->toBe($bookings)
        ->and(DB::table('cleaning_visit_funding_reservations')->count())->toBe(0);
});


test('Filament administrator can approve and publish a reviewed version', function (): void {
    [, $draft] = adminCashCatalogFixture();
    $hash = app(CleaningV3CatalogReviewManifestService::class)->fingerprint($draft);
    \Livewire\Livewire::test(
        \App\Filament\Resources\CleaningV3CatalogVersions\Pages\EditCleaningV3CatalogVersion::class,
        ['record' => $draft->getRouteKey()],
    )->callAction('publishCatalog', data: ['reviewedFingerprint' => $hash, 'approved' => true])
        ->assertHasNoErrors();
    expect($draft->fresh()->status)->toBe('published')
        ->and(CleaningV3AdminCatalogPublication::digest())->toBe($hash);
});
