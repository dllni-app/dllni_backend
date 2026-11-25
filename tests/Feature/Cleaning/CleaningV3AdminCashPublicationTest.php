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


test('cash reconciliation requires linked signed price, closed work, distinct verifier and actual receipt before payout', function (int $workers): void {
    [$admin, $draft] = adminCashCatalogFixture();
    config()->set('cleaning_v3_catalog.cash_settlement_enabled', true);
    $version = app(CleaningV3AdminCatalogPublication::class)->publish($draft,
        app(CleaningV3CatalogReviewManifestService::class)->fingerprint($draft));
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $customer = User::factory()->create(['is_active' => true]);
    $this->actingAs($customer);
    Sanctum::actingAs($customer);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $customer->id, 'street' => 'QA street', 'latitude' => '36.20', 'longitude' => '37.15',
    ]);
    $revision = $this->getJson('/api/v1/cleaning/v3/customer-preferences')->assertOk()->json('data.addresses.0.revision');
    DB::table('cleaning_financial_settings')->delete();
    \App\Models\CleaningFinancialSetting::query()->create([
        ...\Modules\Cleaning\Support\CleaningRuntimeSettings::financialDefaults(),
        'travel_markup_value' => 1000, 'cleaning_minimum_order_price' => 1000,
        'commission_type' => 'percent', 'default_commission_rate' => 10, 'supervisor_hourly_rate' => 5000, 'supervisor_compensation_hourly_rate' => 3000,
    ]);
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $version->version_key,
        'catalogRevision' => $version->published_digest, 'cleaningMode' => 'regular',
        'answers' => ['qa.room' => 2], 'pricingPreviewRequested' => true,
        'preferences' => ['addressId' => $address->id, 'addressRevision' => $revision,
            'genderPreference' => 'any', 'excludedWorkerIds' => []],
        'appointments' => [['appointmentKey' => 'appointment', 'visits' => [[
            'occurrenceKey' => 'visit', 'scheduledDate' => now('Asia/Damascus')->addDay()->toDateString(),
            'scheduledTime' => '09:00', 'durationMinutes' => 120, 'requiredWorkers' => $workers,
        ]]]],
    ];
    $preview = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)->assertOk();
    $form['expectedPricingFingerprint'] = $preview->json('data.commercialPricePreview.pricingFingerprint');
    $form['idempotencyKey'] = (string) \Illuminate\Support\Str::uuid();
    $quote = $this->postJson('/api/v1/cleaning/v3/quotes', $form)->assertOk()->json('data');
    $total = (int) $quote['snapshot']['totalWithAdditionalWorkerFees'];
    $due = number_format($total, 2, '.', '');
    // The task-7 booking writer is deliberately out of scope. This fixture
    // links the genuine test-issued quote to an isolated completed test visit.
    $booking = \Modules\Cleaning\Models\CleaningBooking::factory()->create([
        'customer_id' => $customer->id, 'booking_kind' => 'standard', 'number_of_workers' => $workers,
        'total_price' => $total,
    ]);
    $booking->forceFill(['contract_version' => 'v3', 'catalog_version_id' => $version->id])->save();
    DB::table('cleaning_booking_quotes')->where('id', $quote['quoteId'])->update(['cleaning_booking_id' => $booking->id]);
    $start = now('UTC')->subHours(2)->startOfSecond();
    $end = $start->copy()->addMinutes(90);
    $visit = $booking->sessions()->create([
        'sequence' => 1, 'session_type' => 'recurring_cleaning', 'calculation_mode' => 'task',
        'scheduled_date' => now()->toDateString(), 'scheduled_time' => '09:00', 'duration_hours' => 2,
        'required_workers' => $workers, 'supervision_mode' => $workers === 1 ? 'worker' : 'supervisor', 'coverage_status' => 'fully_covered',
        'status' => 'completed', 'work_started_at' => $start, 'work_finished_at' => $end,
        'customer_completed_at' => now(), 'is_pricing_final' => true, 'payment_status' => 'pending',
        'total_price' => $total,
    ]);
    $visit->forceFill(['appointment_key' => 'appointment', 'occurrence_key' => 'visit', 'price_revision' => 1, 'clock_revision' => 4])->save();
    for ($w = 0; $w < $workers; $w++) {
        $worker = \App\Models\Worker::factory()->create();
        $visit->workerAssignments()->create([
            'worker_id' => $worker->id, 'status' => 'completed', 'accepted_at' => $start->copy()->subMinutes(10),
            'work_started_at' => $start, 'work_finished_at' => $end, 'currency' => 'SYP',
        ]);
    }
    foreach ([[$start, $start->copy()->addMinutes(30)], [$start->copy()->addMinutes(60), $end]] as $i => [$a, $b]) {
        DB::table('cleaning_work_intervals')->insert([
            'cleaning_booking_session_id' => $visit->id, 'start_command_key' => 'qa-start-'.$i, 'end_command_key' => 'qa-end-'.$i,
            'opened_clock_revision' => 1 + $i * 2, 'closed_clock_revision' => 2 + $i * 2,
            'started_at' => $a, 'ended_at' => $b, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $service = app(\Modules\Cleaning\Services\CleaningV3CashSettlementService::class);
    $this->actingAs($admin);
    $evidence = 'QA isolated physical receipt fixture';
    if ($workers === 2) {
        $supervisor = User::factory()->create();
        DB::table('cleaning_supervisor_assignments')->insert([
            'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
            'assigned_by_user_id' => $admin->id, 'assigned_at' => $start->copy()->subMinutes(10),
            'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proof = app(\Modules\Cleaning\Services\CleaningV3SupervisorAttendanceProofService::class);
        expect(fn () => $service->review($booking->id, $visit->id))->toThrow(DomainException::class, 'V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
        $facts = $proof->record($booking->id, $visit->id, $supervisor->id,
            $start->copy()->addMinutes(15)->format('Y-m-d\TH:i:s\Z'), $end->format('Y-m-d\TH:i:s\Z'), 'QA-presence', $evidence);
        expect($facts['active_seconds'])->toBe(2700);
        expect(fn () => $proof->verify($booking->id, $visit->id, 'QA-presence', 2700))->toThrow(DomainException::class);
        $this->actingAs($reviewer);
        $proof->verify($booking->id, $visit->id, 'QA-presence', 2700);
        $this->actingAs($admin);
    }
    $state = $service->review($booking->id, $visit->id);
    expect($state['activeSeconds'])->toBe(3600)->and($state['totalDueSyp'])->toBe($due)
        ->and($state['workerPayables'][0]['grossPayableSyp'])->toBe($workers === 1 ? '201000.00' : '101000.00')
        ->and($state['supervisorPayableSyp'])->toBe($workers === 1 ? '0.00' : '2250.00')
        ->and($state['commissionReceivableSyp'])->toBe('20000.00')
        ->and($state['paymentStatus'])->toBe('pending');
    $beneficiary = array_key_first($state['expectedPayoutsCents']);
    $payable = \Modules\Cleaning\Services\CleaningV3CashSettlementBasis::money($state['expectedPayoutsCents'][$beneficiary]);
    expect(fn () => $service->record($booking->id, $visit->id, 'payout', $beneficiary, $payable, 'QA-payout', $evidence))
        ->toThrow(DomainException::class, 'V3_CASH_MOVEMENT_NOT_ALLOWED');
    $service->record($booking->id, $visit->id, 'collection', 'treasury', $due, 'QA-receipt', $evidence);
    $service->record($booking->id, $visit->id, 'collection', 'treasury', $due, 'QA-receipt', $evidence);
    expect(DB::table('cleaning_v3_cash_movements')->count())->toBe(1);
    expect(fn () => $service->verify($booking->id, $visit->id, 'QA-receipt', $due))
        ->toThrow(DomainException::class, 'V3_CASH_INDEPENDENT_VERIFICATION_REQUIRED');
    $this->actingAs($reviewer);
    expect(fn () => $service->verify($booking->id, $visit->id, 'QA-receipt', '1.00'))
        ->toThrow(DomainException::class, 'V3_CASH_INDEPENDENT_VERIFICATION_REQUIRED');
    $service->verify($booking->id, $visit->id, 'QA-receipt', $due);
    expect($visit->fresh()->payment_status)->toBe('pending');
    foreach ($state['expectedPayoutsCents'] as $beneficiary => $cents) {
        $payable = \Modules\Cleaning\Services\CleaningV3CashSettlementBasis::money($cents);
        $reference = 'QA-payout-'.$beneficiary;
        $this->actingAs($admin);
        $service->record($booking->id, $visit->id, 'payout', $beneficiary, $payable, $reference, $evidence);
        $this->actingAs($reviewer);
        $settled = $service->verify($booking->id, $visit->id, $reference, $payable);
        $service->verify($booking->id, $visit->id, $reference, $payable);
    }
    expect($settled['paymentStatus'])->toBe('settled')->and($settled['allPayablesPosted'])->toBeTrue();
    expect(DB::table('cleaning_v3_cash_movements')->count())->toBe($workers === 1 ? 2 : 4)
        ->and(DB::table('cleaning_visit_funding_reservations')->count())->toBe(0);
    if ($workers === 2) {
        $this->actingAs($admin);
        $fee = $state['additionalWorkerFeeSyp'];
        $service->record($booking->id, $visit->id, 'fee_refund', 'customer', $fee, 'QA-fee-refund', $evidence);
        $this->actingAs($reviewer);
        $refund = $service->verify($booking->id, $visit->id, 'QA-fee-refund', $fee);
        expect($refund['refundedFeeCents'])->toBe(\Modules\Cleaning\Services\CleaningV3CashSettlementBasis::cents($fee));
        expect(fn () => $service->record($booking->id, $visit->id, 'fee_refund', 'customer', '1.00', 'QA-extra-refund', $evidence))
            ->toThrow(DomainException::class, 'V3_CASH_MOVEMENT_NOT_ALLOWED');
    }
        $this->actingAs($customer);
    expect(fn () => $service->review($booking->id, $visit->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
})->with([1, 2]);

test('published catalog refuses deactivated sources and readiness reports valid publication separately', function (): void {
    [, $draft, $item] = adminCashCatalogFixture();
    $version = app(CleaningV3AdminCatalogPublication::class)->publish($draft,
        app(CleaningV3CatalogReviewManifestService::class)->fingerprint($draft));
    $report = app(\Modules\Cleaning\Services\CleaningV3ReleaseReadinessAuditService::class)->audit();
    expect($report['blockerCodes'])->not->toContain('admin_catalog_publication')
        ->and($report['blockerCodes'])->toContain('approved_catalog_coverage_and_funding')
        ->and($report['readyForCommercialLaunch'])->toBeFalse();
    CleaningHomeType::query()->whereKey($item->source_id)->update(['is_active' => false]);
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertStatus(503);
    expect($version->fresh()->status)->toBe('published');
});
