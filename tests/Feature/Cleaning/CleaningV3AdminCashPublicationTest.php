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


test('cash reconciliation requires linked signed price, closed work, distinct verifier and actual receipt before payout', function (int $workers, bool $replacement): void {
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
        'commission_type' => 'percent', 'default_commission_rate' => 10, 'supervisor_hourly_rate' => 5000, 'supervisor_compensation_hourly_rate' => $replacement ? 20000 : 3000,
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
    if ($replacement) {
        $visit->workerAssignments()->orderBy('worker_id')->firstOrFail()->forceFill([
            'status' => 'withdrawn', 'work_finished_at' => null, 'released_at' => $start->copy()->addMinutes(30),
        ])->save();
        $visit->workerAssignments()->create([
            'worker_id' => \App\Models\Worker::factory()->create()->id, 'status' => 'completed',
            'accepted_at' => $start->copy()->addMinutes(55), 'work_started_at' => $start->copy()->addMinutes(60),
            'work_finished_at' => $end, 'currency' => 'SYP',
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
            'released_at' => $replacement ? $start->copy()->addMinutes(30) : null,
            'active_slot' => $replacement ? null : 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($replacement) {
            $other = User::factory()->create();
            DB::table('cleaning_supervisor_assignments')->insert([
                'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $other->id,
                'assigned_by_user_id' => $admin->id, 'assigned_at' => $start->copy()->addMinutes(60),
                'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $proof = app(\Modules\Cleaning\Services\CleaningV3SupervisorAttendanceProofService::class);
        expect(fn () => $service->review($booking->id, $visit->id))->toThrow(DomainException::class, 'V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
        $proofEnd = $replacement ? $start->copy()->addMinutes(30) : $end;
        $seconds = $replacement ? 900 : 2700;
        $facts = $proof->record($booking->id, $visit->id, $supervisor->id,
            $start->copy()->addMinutes(15)->format('Y-m-d\TH:i:s\Z'), $proofEnd->format('Y-m-d\TH:i:s\Z'), 'QA-presence', $evidence);
        expect($facts['active_seconds'])->toBe($seconds);
        expect(fn () => $proof->verify($booking->id, $visit->id, 'QA-presence', $seconds))->toThrow(DomainException::class);
        $this->actingAs($reviewer);
        $proof->verify($booking->id, $visit->id, 'QA-presence', $seconds);
        $this->actingAs($admin);
        if ($replacement) {
            expect(fn () => $service->review($booking->id, $visit->id))->toThrow(DomainException::class, 'V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
            $facts = $proof->record($booking->id, $visit->id, $other->id,
                $start->copy()->addMinutes(60)->format('Y-m-d\TH:i:s\Z'), $end->format('Y-m-d\TH:i:s\Z'), 'QA-presence-other', $evidence);
            expect($facts['active_seconds'])->toBe(1800);
            $this->actingAs($reviewer);
            $proof->verify($booking->id, $visit->id, 'QA-presence-other', 1800);
            $this->actingAs($admin);
        }
    }
    $state = $service->review($booking->id, $visit->id);
    expect($state['activeSeconds'])->toBe(3600)->and($state['totalDueSyp'])->toBe($due)
        ->and($state['workerPayables'][0]['grossPayableSyp'])->toBe($replacement ? '51000.00' : ($workers === 1 ? '201000.00' : '101000.00'))
        ->and($state['supervisorPayableSyp'])->toBe($replacement ? '15000.00' : ($workers === 1 ? '0.00' : '2250.00'))
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
    if ($replacement) {
        $this->actingAs($admin);
        expect($state['remainingFundingGapSyp'])->toBe('6000.00');
        expect(fn () => $service->record($booking->id, $visit->id, 'payout', $beneficiary, $payable, 'QA-payout', $evidence))
            ->toThrow(DomainException::class, 'V3_CASH_MOVEMENT_NOT_ALLOWED');
        $service->record($booking->id, $visit->id, 'funding_topup', 'treasury', '6000.00', 'QA-topup', $evidence);
        expect(fn () => $service->verify($booking->id, $visit->id, 'QA-topup', '6000.00'))->toThrow(DomainException::class);
        $this->actingAs($reviewer);
        expect($service->verify($booking->id, $visit->id, 'QA-topup', '6000.00')['remainingFundingGapSyp'])->toBe('0.00');
    }
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
    $summary = $service->customerSummary($booking->id, $customer->id)[0];
    expect($summary['status'])->toBe('settled')->and($summary['finalAmountSyp'])->toBe($due)
        ->and($summary['collectedAmountSyp'])->toBe($due)->and($summary['refundedAmountSyp'])->toBe('0.00')
        ->and(array_keys($summary))->not->toContain('workerPayables', 'movements', 'collectionReference');
    expect(DB::table('cleaning_v3_cash_movements')->count())->toBe($replacement ? 7 : ($workers === 1 ? 2 : 4))
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
})->with([[1, false], [2, false], [2, true]]);

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


test('confirmed cash booking persists one unpaid held order and exact visits, with safe retry', function (bool $withCoupon): void {
    [, $draft] = adminCashCatalogFixture();
    $asset = \Modules\Cleaning\Models\CleaningSpecialServiceEquipment::query()->create([
        'name' => 'QA equipment', 'asset_code' => 'QA-BOOKING-TOOL', 'status' => 'available',
        'buffer_before_minutes' => 15, 'buffer_after_minutes' => 15, 'is_active' => true,
    ]);
    $draft->items()->create([
        'item_key' => 'qa.tool', 'source_type' => 'equipment', 'source_id' => $asset->id,
        'label_ar' => 'معدات', 'measurement_mode' => 'piece', 'required' => false, 'is_enabled' => true,
        'input_rules' => ['equipmentUnitPriceSyp' => '7500.50'],
    ]);
    config()->set('cleaning_v3_catalog.new_booking_admission_enabled', true);
    $version = app(CleaningV3AdminCatalogPublication::class)->publish($draft,
        app(CleaningV3CatalogReviewManifestService::class)->fingerprint($draft));
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
    ]);
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $version->version_key,
        'catalogRevision' => $version->published_digest, 'cleaningMode' => 'regular',
        'answers' => ['qa.room' => 2, 'qa.tool' => 1], 'pricingPreviewRequested' => true,
        'preferences' => ['addressId' => $address->id, 'addressRevision' => $revision,
            'genderPreference' => 'any', 'excludedWorkerIds' => []],
        'appointments' => [['appointmentKey' => 'appointment', 'visits' => [[
            'occurrenceKey' => 'visit', 'scheduledDate' => now('Asia/Damascus')->addDay()->toDateString(),
            'scheduledTime' => '09:00', 'durationMinutes' => 120, 'requiredWorkers' => 1,
        ]]]],
    ];
    $coupon = null;
    if ($withCoupon) {
        $coupon = \App\Models\PlatformCoupon::query()->create([
            'code' => 'V3HELD', 'title_ar' => 'QA coupon', 'description_ar' => 'QA coupon test', 'section' => 'cleaning',
            'discount_type' => 'fixed', 'discount_value' => 20000,
            'audience_type' => 'all_users', 'is_active' => true, 'total_usage_limit' => 1,
            'per_user_usage_limit' => 1, 'funding_type' => 'platform',
            'cap_platform_funding_to_revenue' => false,
        ]);
        $form['couponCode'] = $coupon->code;
    }
    $preview = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)->assertOk();
    $form['expectedPricingFingerprint'] = $preview->json('data.commercialPricePreview.pricingFingerprint');
    $quote = $this->postJson('/api/v1/cleaning/v3/quotes', [...$form, 'idempotencyKey' => (string) \Illuminate\Support\Str::uuid()])->assertOk()->json('data');
    $confirmation = [
        'quoteId' => $quote['quoteId'], 'idempotencyKey' => $quote['idempotencyKey'],
        'requestHash' => $quote['requestHash'], 'quoteSignature' => $quote['signature'],
        'acceptedTotalSyp' => $quote['snapshot']['totalWithAdditionalWorkerFees'], 'pricingRevision' => 1,
        'pricingFingerprint' => $quote['snapshot']['commercialReview']['pricingFingerprint'],
        'draftFingerprint' => $quote['snapshot']['commercialReview']['draftFingerprint'],
    ];
    $payload = ['confirmation' => $confirmation, 'draft' => $form, 'termsAccepted' => true];
    $url = '/api/v1/cleaning/v3/bookings';
    $this->postJson($url, [...$payload, 'confirmation' => [...$confirmation, 'acceptedTotalSyp' => '1.00']])->assertStatus(409);
    expect(DB::table('cleaning_bookings')->count())->toBe(0);
    $created = $this->postJson($url, $payload)->assertCreated()
        ->assertJsonPath('data.admissionStatus', 'pending_operational_review')
        ->assertJsonPath('data.paymentStatus', 'unpaid')->assertJsonPath('data.canDispatch', false)
        ->assertJsonPath('data.visits.0.occurrenceKey', 'visit')->json('data');
    $booking = \Modules\Cleaning\Models\CleaningBooking::findOrFail($created['bookingId']);
    expect($booking->contract_version)->toBe('v3')
        ->and($booking->sessions()->count())->toBe(1)
        ->and($booking->sessions()->first()->total_price)->toEqual($withCoupon ? 188500.50 : 208500.50)
        ->and($booking->sessions()->first()->payment_status)->toBe('pending')
        ->and(DB::table('cleaning_booking_quotes')->where('id', $quote['quoteId'])->value('cleaning_booking_id'))->toEqual($booking->id)
        ->and(app(\Modules\Cleaning\Services\CleaningGeographicDispatchService::class)->hasOpenWorkerSlots($booking))->toBeFalse();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.bookingId', $booking->id);
    expect(DB::table('cleaning_bookings')->count())->toBe(1)
        ->and(DB::table('cleaning_v3_visit_dispatches')->count())->toBe(0)
        ->and(DB::table('cleaning_v3_cash_movements')->count())->toBe(0);

    if ($withCoupon) {
        expect((int) $coupon->fresh()->used_count)->toBe(1)
            ->and(\App\Models\PlatformCouponRedemption::query()->count())->toBe(1)
            ->and($booking->fresh()->total_price)->toEqual(188500.50);
        $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)->assertStatus(422);
    }
    $this->getJson('/api/v1/cleaning-bookings/'.$booking->id.'/schedule')->assertOk()
        ->assertJsonPath('data.v3AdmissionStatus', 'pending_operational_review')
        ->assertJsonPath('data.v3CashSettlements.0.status', 'awaiting_completion')
        ->assertJsonPath('data.v3CashSettlements.0.finalAmountSyp', null);

    $reservation = \Modules\Cleaning\Models\CleaningEquipmentReservation::query()->where('cleaning_booking_id', $booking->id)->firstOrFail();
    expect($reservation->status)->toBe('reserved')->and((int) $reservation->cleaning_booking_session_id)->toBe($booking->sessions()->first()->id);
    $this->postJson('/api/v1/user/cleaning/orders/'.$booking->id.'/cancel', ['reason' => 'QA cancellation'])->assertOk();
    expect($booking->fresh()->status->value)->toBe('cancelled')
        ->and($reservation->fresh()->status)->toBe('released')
        ->and($booking->sessions()->first()->status->value)->toBe('cancelled');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.admissionStatus', 'cancelled');

    if ($withCoupon) {
        expect((int) $coupon->fresh()->used_count)->toBe(0)
            ->and(\App\Models\PlatformCouponRedemption::query()->whereNotNull('reversed_at')->count())->toBe(1);
    }
    $this->getJson('/api/v1/cleaning-bookings/'.$booking->id.'/schedule')->assertOk()
        ->assertJsonPath('data.v3CashSettlements.0.status', 'cancelled_unpaid')
        ->assertJsonPath('data.v3CashSettlements.0.finalAmountSyp', '0.00');
})->with([false, true]);


test('pending signed revision updates the same visit, preserves historical quote and rejects stale revisions', function (): void {
    [$admin, $draft] = adminCashCatalogFixture();
    config()->set('cleaning_v3_catalog.new_booking_admission_enabled', true);
    $version = app(CleaningV3AdminCatalogPublication::class)->publish($draft,
        app(CleaningV3CatalogReviewManifestService::class)->fingerprint($draft));
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
    $issue = function (array $form, array $amendment = []): array {
        $form['expectedPricingFingerprint'] = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)
            ->assertOk()->json('data.commercialPricePreview.pricingFingerprint');
        $quote = $this->postJson('/api/v1/cleaning/v3/quotes', [...$form, ...$amendment, 'idempotencyKey' => (string) \Illuminate\Support\Str::uuid()])
            ->assertOk()->json('data');
        $confirmation = [
            'quoteId' => $quote['quoteId'], 'idempotencyKey' => $quote['idempotencyKey'],
            'requestHash' => $quote['requestHash'], 'quoteSignature' => $quote['signature'],
            'acceptedTotalSyp' => $quote['snapshot']['totalWithAdditionalWorkerFees'], 'pricingRevision' => $quote['snapshot']['pricingRevision'],
            'pricingFingerprint' => $quote['snapshot']['commercialReview']['pricingFingerprint'],
            'draftFingerprint' => $quote['snapshot']['commercialReview']['draftFingerprint'],
        ];
        return [$quote, ['confirmation' => $confirmation, 'draft' => $form, 'termsAccepted' => true]];
    };
    [$first, $initial] = $issue($form);
    $url = '/api/v1/cleaning/v3/bookings';
    $created = $this->postJson($url, $initial)->assertCreated()->json('data');
    $booking = \Modules\Cleaning\Models\CleaningBooking::findOrFail($created['bookingId']);
    $visit = $booking->sessions()->firstOrFail();
    $form['appointments'][0]['visits'][0]['requiredWorkers'] = 2;
    $form['appointments'][0]['visits'][0]['durationMinutes'] = 150;
    [$second, $amended] = $issue($form, ['revisionBookingId' => $booking->id, 'expectedPriceRevision' => 1]);
    [$stale, $stalePayload] = $issue($form, ['revisionBookingId' => $booking->id, 'expectedPriceRevision' => 1]);
    expect($second['snapshot']['pricingRevision'])->toBe(2)
        ->and($second['snapshot']['amendment']['previousQuoteId'])->toBe($first['quoteId']);
    $this->postJson($url, $amended)->assertCreated()->assertJsonPath('data.bookingId', $booking->id)
        ->assertJsonPath('data.visits.0.visitId', $visit->id)->assertJsonPath('data.visits.0.priceRevision', 2);
    expect($visit->fresh()->requiredWorkerCount())->toBe(2)
        ->and((float) $visit->fresh()->duration_hours)->toBe(2.5)
        ->and(DB::table('cleaning_bookings')->count())->toBe(1)
        ->and(DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->count())->toBe(2)
        ->and($booking->fresh()->property_details['v3_current_quote_id'])->toBe($second['quoteId']);
    $this->postJson($url, $amended)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson($url, $stalePayload)->assertStatus(409);
    $this->postJson($url, $initial)->assertStatus(409);
    expect($booking->sessions()->count())->toBe(1)->and(DB::table('cleaning_v3_visit_dispatches')->count())->toBe(0);
    $draftUrl = '/api/v1/cleaning/v3/bookings/'.$booking->id.'/revision-draft';
    $this->getJson($draftUrl)->assertOk()->assertJsonPath('data.priceRevision', 2)
        ->assertJsonPath('data.draft.appointments.0.visits.0.durationMinutes', 150);
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->getJson($draftUrl)->assertNotFound();
    $this->actingAs($admin);
    $review = app(\Modules\Cleaning\Services\CleaningV3OperationalAdmissionReview::class)->review($booking->id);
    expect($review['signedPriceVerified'])->toBeTrue()->and($review['canDispatch'])->toBeFalse()
        ->and($review['blockers'])->toContain('CLEARED_FUNDING_SOURCE_INTEGRATION_REQUIRED');

    // Test-only isolated provider signature fixture; never a production credit.
    $fundingUrl = "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$visit->id}";
    Sanctum::actingAs($admin);
    $requirement = $this->getJson($fundingUrl.'/funding-requirement')->assertOk()->assertJsonPath('data.verified', false)->json('data');
    $this->postJson($fundingUrl.'/funding-reservation', ['proof' => ['payload' => [], 'signature' => 'invalid']])->assertStatus(409);
    $pair = sodium_crypto_sign_keypair();
    config()->set('cleaning_v3_catalog.treasury_provider', 'isolated-test-custodian');
    config()->set('cleaning_v3_catalog.treasury_public_key', base64_encode(sodium_crypto_sign_publickey($pair)));
    $sign = static function (array $data) use ($pair): array {
        ksort($data);
        return ['payload' => $data, 'signature' => base64_encode(sodium_crypto_sign_detached(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), sodium_crypto_sign_secretkey($pair)))];
    };
    $allocation = [
        'schemaVersion' => 1, 'provider' => 'isolated-test-custodian', 'owner' => 'cleaning_platform', 'currency' => 'SYP',
        'creditId' => 'isolated-test-credit', 'allocationId' => (string) \Illuminate\Support\Str::uuid(),
        'bookingId' => $booking->id, 'visitId' => $visit->id, 'quoteId' => $requirement['quoteId'],
        'pricingRevision' => $requirement['pricingRevision'], 'pricingDigest' => $requirement['pricingDigest'],
        'grossCreditSyp' => '10000000.00', 'reservedSyp' => $requirement['requiredFundingSyp'],
        'issuedAt' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'expiresAt' => now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z'),
        'state' => 'reserved', 'supersedesAllocationId' => null,
    ];
    $proof = $sign($allocation);
    $wrong = $proof;
    $wrong['payload']['grossCreditSyp'] = '20000000.00';
    $this->postJson($fundingUrl.'/funding-reservation', ['proof' => $wrong])->assertStatus(409);
    $this->postJson($fundingUrl.'/funding-reservation', ['proof' => $proof])->assertOk()->assertJsonPath('data.verified', true);
    $this->postJson($fundingUrl.'/funding-reservation', ['proof' => $proof])->assertOk()->assertJsonPath('data.verified', true);
    expect(DB::table('cleaning_visit_funding_reservations')->count())->toBe(1);
    $review = app(\Modules\Cleaning\Services\CleaningV3OperationalAdmissionReview::class)->review($booking->id);
    expect($review['fundingVerified'])->toBeTrue()->and($review['canDispatch'])->toBeFalse();

    // The worker offer must use the same frozen signed commercial terms as
    // the funding requirement, even after the runtime settings change.
    $pricing = app(\Modules\Cleaning\Services\CleaningBookingSessionWorkerPricingService::class);
    $worker = \App\Models\Worker::factory()->create();
    $before = $pricing->quoteForNextSeat($visit->fresh(), $worker, 0);
    $other = $pricing->quoteForNextSeat($visit->fresh(), $worker, 1);
    expect(round($before['workerAmount'] + $other['workerAmount'], 2))
        ->toBe((float) $requirement['workerObligationSyp']);
    DB::table('cleaning_financial_settings')->update(['travel_markup_value' => 80000]);
    expect($pricing->quoteForNextSeat($visit->fresh(), $worker, 0))->toBe($before);
    expect(fn () => $pricing->quoteForNextSeat($visit->fresh(), $worker, 2))
        ->toThrow(\DomainException::class, 'V3_WORKER_SEAT_UNAVAILABLE');
    expect(fn () => DB::transaction(fn () => app(\Modules\Cleaning\Services\CleaningV3PendingRevisionService::class)
        ->lock($customer->id, $booking->id, 2)))
        ->toThrow(\DomainException::class, 'V3_REVISION_FUNDING_RECONCILIATION_REQUIRED');

    // Simulate an already admitted visit solely in this isolated integration test.
    $booking->refresh();
    $visit->refresh();
    $booking->forceFill(['property_details' => [...$booking->property_details, 'v3_admission_hold' => false]])->saveQuietly();
    $visit->forceFill(['status' => 'in_progress', 'work_started_at' => now()])->saveQuietly();
    for ($i = 0; $i < 2; $i++) {
        $workerUser = User::factory()->create(['is_active' => true]);
        $worker = \App\Models\Worker::factory()->create(['user_id' => $workerUser->id]);
        $visit->workerAssignments()->create([
            'worker_id' => $worker->id, 'status' => 'in_progress',
            'accepted_at' => now()->subMinutes(2 - $i), 'work_started_at' => now(),
        ]);
    }
    \Spatie\Permission\Models\Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'active_slot' => 1, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(\Modules\Cleaning\Services\CleaningV3WorkClockCommandService::class)->initializeApprovedVisit($booking->id, $visit->id);
    $this->travel(20)->minutes();
    Sanctum::actingAs($customer);
    $pause = $this->postJson($fundingUrl.'/pause-requests', ['commandKey' => 'pause-extension', 'expectedRevision' => 1])
        ->assertOk()->json('pauseRequestId');
    Sanctum::actingAs($supervisor);
    $this->postJson($fundingUrl.'/pause-requests/'.$pause.'/decision', ['commandKey' => 'pause-ok', 'expectedRevision' => 2, 'decision' => 'approve'])
        ->assertOk()->assertJsonPath('data.state', 'paused');
    $ext = $fundingUrl.'/live-extensions';
    Sanctum::actingAs($customer);
    $this->getJson($ext.'/context')->assertOk()->assertJsonPath('data.canQuote', true)
        ->assertJsonPath('data.canConfirm', false)->assertJsonPath('data.priceRevision', 2);
    $offer = $this->postJson($ext.'/quote', ['additionalMinutes' => 30, 'expectedPriceRevision' => 2, 'expectedClockRevision' => 3, 'commandKey' => 'extend-30'])
        ->assertOk()->assertJsonPath('data.newMinutes', 180)->assertJsonPath('data.additionalAmountSyp', '0.00')->json('data');
    $path = $ext.'/'.$offer['extensionId'];
    $this->getJson($ext.'/context')->assertOk()->assertJsonPath('data.canConfirm', true)
        ->assertJsonPath('data.offer.extensionId', $offer['extensionId']);
    $this->postJson($path.'/confirm', ['signature' => $offer['signature'], 'acceptedTotalSyp' => '1.00', 'accepted' => true])->assertStatus(409);
    $this->postJson($path.'/confirm', ['signature' => $offer['signature'], 'acceptedTotalSyp' => $offer['newTotalSyp'], 'accepted' => true])
        ->assertOk()->assertJsonPath('data.status', 'customer_confirmed');
    expect((int) $visit->fresh()->price_revision)->toBe(2);
    $this->postJson($path.'/decision', ['decision' => 'approve'])->assertNotFound();
    Sanctum::actingAs($supervisor);
    $this->getJson($ext.'/context')->assertOk()->assertJsonPath('data.canDecide', true)
        ->assertJsonPath('data.canConfirm', false);
    $this->postJson($path.'/decision', ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'crew_approved');
    Sanctum::actingAs($admin);
    $next = $this->getJson($path.'/funding-requirement')->assertOk()->json('data');
    $replacement = $sign([...$allocation, 'allocationId' => (string) \Illuminate\Support\Str::uuid(),
        'pricingRevision' => $next['pricingRevision'], 'pricingDigest' => $next['pricingDigest'],
        'reservedSyp' => $next['requiredFundingSyp'], 'supersedesAllocationId' => $allocation['allocationId']]);
    $this->postJson($path.'/apply-funding', ['proof' => $proof])->assertStatus(409);
    $this->postJson($path.'/apply-funding', ['proof' => $replacement])->assertOk()->assertJsonPath('data.status', 'applied');
    $this->postJson($path.'/apply-funding', ['proof' => $replacement])->assertOk()->assertJsonPath('data.status', 'applied');
    expect((int) $visit->fresh()->price_revision)->toBe(3)->and((float) $visit->fresh()->duration_hours)->toBe(3.0)
        ->and(DB::table('cleaning_visit_funding_reservations')->whereNotNull('active_visit_id')->count())->toBe(1)
        ->and(DB::table('cleaning_visit_funding_reservations')->whereNotNull('released_at')->count())->toBe(1);
    Sanctum::actingAs($supervisor);
    $this->postJson($fundingUrl.'/resume', ['commandKey' => 'stale-resume', 'expectedRevision' => 3])->assertStatus(409);
    $this->postJson($fundingUrl.'/resume', ['commandKey' => 'resume-extension', 'expectedRevision' => 4])
        ->assertOk()->assertJsonPath('data.state', 'running')->assertJsonPath('data.activeSeconds', 1200);
});
