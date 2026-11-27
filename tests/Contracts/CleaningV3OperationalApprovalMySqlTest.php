<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\CleaningV3QaCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Middleware\RejectUnapprovedV3WorkerLifecycle;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3AdminCatalogPublication;
use Modules\Cleaning\Services\CleaningV3CatalogReviewManifestService;
use Modules\Cleaning\Services\CleaningV3OperationalAdmissionPolicy;
use Modules\Cleaning\Services\CleaningV3OperationalAdmissionReview;
use Modules\Cleaning\Services\CleaningV3OperationalApprovalService;
use Modules\Cleaning\Services\CleaningV3QaEnvironment;
use Modules\Cleaning\Services\CleaningV3ReleaseReadiness;
use Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope;
use Modules\Cleaning\Services\CleaningSupervisorAssignmentService;
use Spatie\Permission\Models\Role;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    // Deliberately avoid RefreshDatabase: this suite uses only rollbackable
    // fixtures on the existing authorized local QA database.
    if (! app(CleaningV3QaEnvironment::class)->isLocalDatabase()) {
        $this->markTestSkipped('Not the authorized local QA database.');
    }
    DB::beginTransaction();
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
});

afterEach(function (): void {
    if (DB::connection()->transactionLevel() > 0) {
        DB::rollBack(0);
    }
});

test('V3 operational approval atomically opens signed manual cash visits without collecting money', function (bool $qa): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);
    config()->set([
        'cleaning_v3_catalog.admin_publication_enabled' => true,
        'cleaning_v3_catalog.read_enabled' => true,
        'cleaning_v3_catalog.cash_quote_enabled' => true,
        'cleaning_v3_catalog.new_quote_archive_enabled' => true,
        'cleaning_v3_catalog.new_booking_admission_enabled' => true,
        'cleaning_v3_catalog.operational_approval_enabled' => false,
        'cleaning_v3_catalog.qa_enabled' => $qa,
        'cleaning_v3_catalog.qa_signing_key' => $qa ? bin2hex(random_bytes(32)) : null,
        // Metadata is a fixture only; it must not make the real release audit pass.
        'cleaning_worker_update' => $qa ? ['enabled' => false] : [
            'enabled' => true, 'package_id' => 'com.dllni.clOwner',
            'min_supported_version_code' => 1, 'latest_version_code' => 1,
            'apk_sha256' => str_repeat('a', 64), 'signing_certificate_sha256' => str_repeat('b', 64),
            'download_url' => 'https://qa.invalid/worker.apk', 'allowed_download_host' => 'qa.invalid',
        ],
    ]);
    if ($qa) {
        app()->call([app(CleaningV3QaCatalogSeeder::class), 'run']);
        $draft = CleaningV3CatalogVersion::query()->where('version_key', CleaningV3QaEnvironment::CATALOG_KEY)->sole();
    } else {
        $sourceKey = 'qa_wp01_'.Str::lower(Str::random(10));
        $source = CleaningHomeType::query()->create([
            'section' => CleaningHomeType::SECTION_PROPERTY, 'code' => $sourceKey,
            'booking_value' => $sourceKey, 'title' => 'QA approval property', 'is_active' => true,
        ]);
        $draft = CleaningV3CatalogVersion::query()->create(['title' => 'QA approval catalog']);
        $item = $draft->items()->create([
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
    }
    $pointer = DB::table('cleaning_v3_catalog_publication')->where('id', 1)->first();
    $catalog = $draft->status === CleaningV3CatalogVersion::PUBLISHED ? $draft
        : app(CleaningV3AdminCatalogPublication::class)->publish($draft,
            app(CleaningV3CatalogReviewManifestService::class)->fingerprint($draft));
    if ($qa) {
        expect(DB::table('cleaning_v3_catalog_publication')->where('id', 1)->first())->toEqual($pointer);
        expect(fn () => app(CleaningV3AdminCatalogPublication::class)->duplicate($catalog))
            ->toThrow(DomainException::class, 'V3_QA_CATALOG_DUPLICATION_UNAVAILABLE');
        $this->getJson('/api/v1/cleaning/catalog/v3')->assertOk()
            ->assertJsonPath('data.catalogVersionKey', CleaningV3QaEnvironment::CATALOG_KEY);
        config()->set([
            'cleaning_v3_catalog.qa_enabled' => false,
            'cleaning_v3_catalog.admin_publication_enabled' => false,
            'cleaning_v3_catalog.approved_publication_digest' => $catalog->published_digest,
        ]);
        expect(CleaningV3AdminCatalogPublication::digest())->toBeNull();
        config()->set([
            'cleaning_v3_catalog.qa_enabled' => true,
            'cleaning_v3_catalog.admin_publication_enabled' => true,
            'cleaning_v3_catalog.approved_publication_digest' => null,
        ]);
    }
    $settings = CleaningFinancialSetting::query()->orderBy('id')->first();
    ($settings ?? new CleaningFinancialSetting)->fill([
        ...\Modules\Cleaning\Support\CleaningRuntimeSettings::financialDefaults(),
        'travel_markup_value' => 1000, 'cleaning_minimum_order_price' => 1000,
        'v3_supervisor_visit_compensation' => 15000,
    ])->saveQuietly();

    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $customer->id, 'street' => 'QA street', 'latitude' => '36.20', 'longitude' => '37.15',
    ]);
    $addressRevision = $this->getJson('/api/v1/cleaning/v3/customer-preferences')->assertOk()
        ->json('data.addresses.0.revision');
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $catalog->version_key,
        'catalogRevision' => $catalog->published_digest, 'cleaningMode' => 'regular',
        'answers' => ['qa.room' => 2], 'pricingPreviewRequested' => true,
        'preferences' => ['addressId' => $address->id, 'addressRevision' => $addressRevision,
            'genderPreference' => 'any', 'excludedWorkerIds' => []],
        'appointments' => array_map(fn ($n) => [
            'appointmentKey' => 'appointment-'.$n, 'visits' => [[
                'occurrenceKey' => 'visit-'.$n, 'scheduledDate' => now('Asia/Damascus')->addDays($n + 1)->toDateString(),
                'scheduledTime' => '09:00', 'durationMinutes' => 150, 'requiredWorkers' => 2,
            ]],
        ], [1, 2]),
    ];
    $form['expectedPricingFingerprint'] = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)
        ->assertOk()->json('data.commercialPricePreview.pricingFingerprint');
    $quote = $this->postJson('/api/v1/cleaning/v3/quotes', [...$form, 'idempotencyKey' => (string) Str::uuid()])
        ->assertOk()->json('data');
    $confirmation = [
        'quoteId' => $quote['quoteId'], 'idempotencyKey' => $quote['idempotencyKey'],
        'requestHash' => $quote['requestHash'], 'quoteSignature' => $quote['signature'],
        'acceptedTotalSyp' => $quote['snapshot']['totalWithAdditionalWorkerFees'],
        'pricingRevision' => $quote['snapshot']['pricingRevision'],
        'pricingFingerprint' => $quote['snapshot']['commercialReview']['pricingFingerprint'],
        'draftFingerprint' => $quote['snapshot']['commercialReview']['draftFingerprint'],
    ];
    $bookingId = $this->postJson('/api/v1/cleaning/v3/bookings',
        ['confirmation' => $confirmation, 'draft' => $form, 'termsAccepted' => true])
        ->assertCreated()->json('data.bookingId');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $visits = $booking->sessions()->orderBy('sequence')->get();
    $ids = $visits->pluck('id')->map(fn ($id) => (int) $id)->all();
    $approval = app(CleaningV3OperationalApprovalService::class);
    $policy = app(CleaningV3OperationalAdmissionPolicy::class);
    expect($ids)->toHaveCount(2);
    expect(fn () => $approval->approve($bookingId))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Sanctum::actingAs($admin);
    expect($approval->approve($bookingId)['blockers'])->toBe(['V3_OPERATIONAL_APPROVAL_DISABLED']);
    config()->set('cleaning_v3_catalog.operational_approval_enabled', true);
    $review = app(CleaningV3OperationalAdmissionReview::class)->review($bookingId);
    expect($review['signedPriceVerified'])->toBeTrue()->and($review['paymentPolicyReady'])->toBeTrue()
        ->and($review['fundingVerified'])->toBeFalse()->and($review['canDispatch'])->toBe($qa);
    if ($qa) {
        expect($review['admissionScope'])->toBe('qa')
            ->and($review['releaseBlockers'])->toContain('signed_v3_financial_admission');
        $envelope = json_decode(DB::table('cleaning_booking_quotes')->where('id', $quote['quoteId'])->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $verify = fn (array $payload) => app(CleaningV3SignedQuoteEnvelope::class)
            ->verify($payload, (int) $customer->id, $catalog->version_key, $catalog->published_digest);
        expect(fn () => $verify([...$envelope, 'signature' => str_repeat('0', 64)]))
            ->toThrow(DomainException::class, 'V3_QUOTE_SIGNATURE_MISMATCH');
        $env = config('app.env');
        config()->set('app.env', 'production');
        try {
            expect($approval->approve($bookingId)['blockers'])->toContain('V3_QA_PUBLICATION_NOT_READY');
            expect(fn () => $verify($envelope))->toThrow(DomainException::class, 'V3_QA_SIGNING_UNAVAILABLE');
        } finally {
            config()->set('app.env', $env);
        }
        $default = config('database.default');
        try {
            config()->set('database.default', 'sqlite');
            expect(app(CleaningV3QaEnvironment::class)->signingKey())->toBeNull();
        } finally {
            config()->set('database.default', $default);
        }
    } else {
        expect($approval->approve($bookingId)['blockers'])->toContain('V3_COMMERCIAL_RELEASE_NOT_READY');
        config()->set([
            'cleaning_v3_catalog.qa_enabled' => true,
            'cleaning_v3_catalog.qa_signing_key' => bin2hex(random_bytes(32)),
        ]);
        try {
            $commercialReview = app(CleaningV3OperationalAdmissionReview::class)->review($bookingId);
            expect($commercialReview['admissionScope'])->toBe('commercial')
                ->and($commercialReview['canDispatch'])->toBeFalse()
                ->and($commercialReview['blockers'])->toContain('V3_COMMERCIAL_RELEASE_NOT_READY');
        } finally {
            config()->set('cleaning_v3_catalog.qa_enabled', false);
        }
    }
    expect($policy->approved($booking))->toBeFalse();
    $tampering = ['scheduled_time' => '10:00'];
    if ($qa) {
        $tampering += ['duration_hours' => '3.00', 'required_workers' => 3, 'total_price' => '1.00'];
    }
    $cashCount = DB::table('cleaning_v3_cash_movements')->count();
    foreach ($tampering as $field => $changed) {
        $original = $visits[0]->getRawOriginal($field);
        $visits[0]->forceFill([$field => $changed])->saveQuietly();
        expect(app(CleaningV3OperationalAdmissionReview::class)->review($bookingId)['blockers'])
            ->toContain('SIGNED_PRICE_OR_VISIT_MISMATCH');
        if ($qa) {
            expect($approval->approve($bookingId)['approved'])->toBeFalse();
        }
        $visits[0]->forceFill([$field => $original])->saveQuietly();
    }
    if ($qa) {
        expect(DB::table('cleaning_v3_operational_approvals')->where('booking_id', $bookingId)->exists())->toBeFalse()
            ->and(DB::table('cleaning_v3_cash_movements')->count())->toBe($cashCount);
    }
    $booking->forceFill(['property_details' => [...$booking->property_details, 'v3_admission_hold' => false]])->saveQuietly();
    expect($policy->approved($booking))->toBeFalse();
    Sanctum::actingAs($customer);
    $this->getJson('/api/v1/cleaning-bookings/'.$bookingId.'/schedule')
        ->assertOk()->assertJsonPath('data.v3AdmissionStatus', 'pending_operational_review');
    Sanctum::actingAs($admin);
    $booking->forceFill(['property_details' => [...$booking->property_details, 'v3_admission_hold' => true]])->saveQuietly();

    // Replace ONLY the independent external release-evidence boundary in
    // this rollback-only test. Real signed prices, manual payment policy,
    // authorization, MySQL writes and search transitions are not mocked.
    if (! $qa) {
        app()->instance(CleaningV3ReleaseReadiness::class, new class implements CleaningV3ReleaseReadiness {
            public function audit(): array
            {
                return ['readyForCommercialLaunch' => true, 'blockerCodes' => []];
            }
        });
    }
    $before = DB::table('cleaning_v3_cash_movements')->count();
    // An aborted surrounding transaction must roll back every approval write.
    expect(fn () => DB::transaction(function () use ($approval, $bookingId): void {
        expect($approval->approve($bookingId)['approved'])->toBeTrue();
        throw new \RuntimeException('qa rollback');
    }))->toThrow(\RuntimeException::class, 'qa rollback');
    expect($policy->approved($booking->fresh()))->toBeFalse()
        ->and(DB::table('cleaning_v3_operational_approvals')->where('booking_id', $bookingId)->exists())->toBeFalse()
        ->and(DB::table('cleaning_v3_visit_dispatches')->whereIn('cleaning_booking_session_id', $ids)->count())->toBe(0);

    $result = $approval->approve($bookingId);
    expect($result['approved'])->toBeTrue()->and($result['visitIds'])->toBe($ids)
        ->and($result['quoteId'])->toBe($quote['quoteId']);
    $booking->refresh();
    expect($policy->approved($booking, $visits[0]))->toBeTrue()
        ->and($policy->approved($booking, $visits[1]))->toBeTrue()
        ->and($booking->property_details['v3_admission_hold'])->toBeFalse()
        ->and($booking->sessions()->where('payment_status', 'pending')->count())->toBe(2)
        ->and(DB::table('cleaning_v3_cash_movements')->count())->toBe($before);
    if ($qa) {
        config()->set('cleaning_v3_catalog.qa_enabled', false);
        expect($policy->approved($booking, $visits[0]))->toBeFalse();
        config()->set('cleaning_v3_catalog.qa_enabled', true);
    }
    $dispatches = DB::table('cleaning_v3_visit_dispatches')->whereIn('cleaning_booking_session_id', $ids)->orderBy('id')->get();
    expect($dispatches)->toHaveCount(2);
    foreach ($dispatches as $dispatch) {
        expect(strtotime($dispatch->deadline_at) - strtotime($dispatch->first_search_started_at))->toBe(21600);
    }
    Sanctum::actingAs($customer);
    $this->getJson('/api/v1/cleaning-bookings/'.$bookingId.'/schedule')
        ->assertOk()->assertJsonPath('data.v3AdmissionStatus', 'approved');
    Sanctum::actingAs($admin);
    config()->set('cleaning_v3_catalog.operational_approval_enabled', false);
    expect($approval->approve($bookingId)['replayed'])->toBeTrue()
        ->and(DB::table('cleaning_v3_visit_dispatches')->whereIn('cleaning_booking_session_id', $ids)->count())->toBe(2);
    expect(app(CleaningSupervisorAssignmentService::class)->isEligible($booking))->toBeTrue();

    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id, 'trust_score' => 100,
        'home_latitude' => 36.20, 'home_longitude' => 37.15,
    ]);
    Sanctum::actingAs($workerUser);
    $url = "/api/v1/cleaning-bookings/{$bookingId}";
    $this->postJson($url.'/sessions/accept-selected', ['sessionIds' => [$ids[0]]])->assertStatus(426);
    $headers = [RejectUnapprovedV3WorkerLifecycle::HEADER => RejectUnapprovedV3WorkerLifecycle::CAPABILITY];
    // The supervised parent route is rejected by its earlier custody guard.
    $this->withHeaders($headers)->postJson($url.'/accept', [])->assertForbidden();
    $this->withHeaders($headers)->postJson($url.'/sessions/accept-selected', ['sessionIds' => [$ids[0]]])
        ->assertOk()->assertJsonPath('data.acceptance.acceptedSessionIds.0', $ids[0]);
    expect($visits[0]->workerAssignments()->where('worker_id', $worker->id)->count())->toBe(1)
        ->and($visits[1]->workerAssignments()->where('worker_id', $worker->id)->count())->toBe(0);

    $this->postJson($url.'/sessions/'.$ids[0].'/start-travel')->assertUnprocessable();
    expect($visits[0]->fresh()->started_travel_at)->toBeNull();

    Sanctum::actingAs($admin);
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole(Role::findOrCreate(\Modules\Cleaning\Services\SupervisedTeamService::ROLE, 'web'));
    $assigned = app(CleaningSupervisorAssignmentService::class)
        ->assign($admin, $bookingId, $supervisor->id, null, true);
    expect($assigned['supervisorUserId'])->toBe((int) $supervisor->id);
    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/bookings/{$bookingId}")
        ->assertOk()->assertJsonPath('data.v3VisitIds', $ids)
        ->assertJsonPath('data.v3Visits.0.acceptedWorkers', 1)
        ->assertJsonPath('data.v3Visits.1.acceptedWorkers', 0)
        ->assertJsonPath('data.v3Visits.0.workers.0.workerId', (int) $worker->id)
        ->assertJsonPath('data.v3Visits.1.workers', [])
        ->assertJsonPath('data.allowedActions', []);
    $this->postJson("/api/v1/supervisor/bookings/{$bookingId}/start")->assertStatus(409);
    $otherSupervisor = User::factory()->create(['is_active' => true]);
    $otherSupervisor->assignRole(Role::findOrCreate(\Modules\Cleaning\Services\SupervisedTeamService::ROLE, 'web'));
    Sanctum::actingAs($otherSupervisor);
    $this->getJson("/api/v1/supervisor/bookings/{$bookingId}")->assertNotFound();

    if ($qa) {
        $visitUrl = $url.'/sessions/'.$ids[0];
        $supervisorVisit = "/api/v1/supervisor/bookings/{$bookingId}/visits/{$ids[0]}";
        $customerCode = "/api/v1/supervised-team/customer/bookings/{$bookingId}/visits/{$ids[0]}/verification-code";
        $secondUser = User::factory()->create(['is_active' => true]);
        Worker::factory()->financiallyEligible()->create([
            'user_id' => $secondUser->id, 'trust_score' => 100,
            'home_latitude' => 36.20, 'home_longitude' => 37.15,
        ]);
        Sanctum::actingAs($secondUser);
        $this->postJson($url.'/sessions/accept-selected', ['sessionIds' => [$ids[0]]])->assertOk();
        Sanctum::actingAs($workerUser);
        $this->postJson($visitUrl.'/start-travel')->assertOk();
        $this->postJson($visitUrl.'/arrive')->assertOk();
        $this->getJson($visitUrl.'/security-code')->assertConflict()
            ->assertJsonPath('code', 'V3_SUPERVISOR_EXECUTION_REQUIRED');

        Sanctum::actingAs($supervisor);
        $this->postJson($supervisorVisit.'/verification-requests')->assertConflict();
        Sanctum::actingAs($secondUser);
        $this->postJson($visitUrl.'/start-travel')->assertOk();
        $this->postJson($visitUrl.'/arrive')->assertOk();
        Sanctum::actingAs($otherSupervisor);
        $this->postJson($supervisorVisit.'/verification-requests')->assertNotFound();
        Sanctum::actingAs($supervisor);
        $this->postJson($supervisorVisit.'/start')->assertConflict();
        $requestCode = $this->postJson($supervisorVisit.'/verification-requests')->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.code')->json('data');
        $this->postJson($supervisorVisit.'/verification-requests')->assertOk()->assertJsonPath('data', $requestCode);
        $this->getJson("/api/v1/supervisor/bookings/{$bookingId}")
            ->assertOk()->assertJsonPath('data.v3Visits.0.verificationStatus', 'pending');
        $this->getJson($customerCode)->assertNotFound();

        Sanctum::actingAs($customer);
        $this->getJson($url.'/schedule')->assertOk()
            ->assertJsonPath('data.schedule.sessions.0.canViewSupervisorCode', true)
            ->assertJsonPath('data.schedule.sessions.1.canViewSupervisorCode', false);
        $this->postJson($visitUrl.'/start-verification/confirm', ['code' => '1111'])->assertConflict();
        $response = $this->getJson($customerCode)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        $code = $response->json('data.code');
        expect($code)->toMatch('/^[0-9]{6}$/D');
        Sanctum::actingAs($supervisor);
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->postJson($supervisorVisit.'/verification/confirm', ['code' => $wrong])->assertUnprocessable();
        expect(DB::table('cleaning_v3_visit_verifications')->where('visit_id', $ids[0])->value('attempts'))->toEqual(1);
        $this->postJson($supervisorVisit.'/verification/confirm', ['code' => $code])->assertOk();
        $this->postJson($supervisorVisit.'/start')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson($supervisorVisit.'/start')->assertOk();
        expect(DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $ids[0])->count())->toBe(1);
        $this->travel(2)->seconds();
        Sanctum::actingAs($customer);
        $rejectedPause = $this->postJson($visitUrl.'/pause-requests', [
            'commandKey' => 'supervised-pause-first', 'expectedRevision' => 1,
        ])->assertOk()->assertJsonPath('data.state', 'running')->json('pauseRequestId');
        Sanctum::actingAs($supervisor);
        $this->postJson($visitUrl.'/pause-requests/'.$rejectedPause.'/decision', [
            'commandKey' => 'supervised-pause-rejected', 'expectedRevision' => 2, 'decision' => 'reject',
        ])->assertOk()->assertJsonPath('data.state', 'running');
        Sanctum::actingAs($customer);
        $pauseRequest = $this->postJson($visitUrl.'/pause-requests', [
            'commandKey' => 'supervised-pause', 'expectedRevision' => 3,
        ])->assertOk()->json('pauseRequestId');
        Sanctum::actingAs($supervisor);
        $this->postJson($visitUrl.'/pause-requests/'.$pauseRequest.'/decision', [
            'commandKey' => 'supervised-pause-approved', 'expectedRevision' => 4, 'decision' => 'approve',
        ])->assertOk()->assertJsonPath('data.state', 'paused');
        $this->travel(2)->seconds();
        Sanctum::actingAs($otherSupervisor);
        $this->postJson($visitUrl.'/resume', [
            'commandKey' => 'unassigned-supervisor-resume', 'expectedRevision' => 5,
        ])->assertNotFound();
        Sanctum::actingAs($workerUser);
        $this->postJson($visitUrl.'/resume', [
            'commandKey' => 'worker-resume-supervised-visit', 'expectedRevision' => 5,
        ])->assertNotFound();
        Sanctum::actingAs($supervisor);
        $this->postJson($visitUrl.'/resume', [
            'commandKey' => 'supervised-stale-resume', 'expectedRevision' => 4,
        ])->assertConflict()->assertJsonPath('code', 'STALE_CLOCK_REVISION');
        $this->postJson($visitUrl.'/resume', [
            'commandKey' => 'supervised-resume', 'expectedRevision' => 5,
        ])->assertOk()->assertJsonPath('data.state', 'running');
        $this->travel(2)->seconds();
        $this->postJson($supervisorVisit.'/handover-requests')->assertOk()
            ->assertJsonPath('data.status', 'awaiting_customer_completion');
        $this->postJson($supervisorVisit.'/handover-requests')->assertOk();
        $this->postJson($visitUrl.'/completion/confirm')->assertForbidden();
        Sanctum::actingAs($customer);
        $this->getJson($customerCode)->assertNotFound();
        $this->postJson($visitUrl.'/completion/reject', ['reason' => 'QA handover review'])->assertOk();
        $this->travel(2)->seconds();
        Sanctum::actingAs($supervisor);
        $this->postJson($supervisorVisit.'/handover-requests')->assertOk();
        Sanctum::actingAs($customer);
        $this->postJson($visitUrl.'/completion/confirm')->assertOk();
        $this->travelBack();
        expect($visits[0]->fresh()->status->value)->toBe('completed')
            ->and($visits[0]->fresh()->payment_status)->toBe('pending')
            ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $ids[0])->exists())->toBeFalse()
            ->and(DB::table('cleaning_v3_cash_movements')->count())->toBe($before);
        expect($visits[1]->fresh()->arrived_at)->toBeNull();
        expect($visits[1]->fresh()->status->value)->toBe('scheduled');
    }
})->with(['commercial evidence contract' => [false], 'isolated QA admission' => [true]]);

test('failed visit verification attempts persist and leave a sibling visit untouched', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id, 'status' => CleaningBookingStatus::WorkerAssigned,
        'property_type' => 'event_assistance', 'contract_version' => null, 'number_of_workers' => 1,
    ]);
    $dates = [now()->addDay()->toDateString(), now()->addDays(2)->toDateString()];
    $visits = collect($dates)->map(fn (string $date, int $index) => $booking->sessions()->create([
        'sequence' => $index + 1, 'session_type' => 'event_day', 'calculation_mode' => 'hours',
        'scheduled_date' => $date, 'scheduled_time' => '10:00', 'duration_hours' => 2,
        'required_workers' => 1, 'coverage_status' => 'fully_covered',
        'status' => CleaningBookingSessionStatus::WorkerAssigned, 'total_price' => 3300,
    ]));
    foreach ($visits as $visit) {
        $visit->workerAssignments()->create([
            'worker_id' => $worker->id, 'accepted_at' => now(), 'worker_amount' => 3000, 'currency' => 'SYP',
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        ]);
    }
    $path = "/api/v1/cleaning-bookings/{$booking->id}/sessions/";
    Sanctum::actingAs($workerUser);
    $codes = $visits->map(function ($visit) use ($path): string {
        $this->postJson($path.$visit->id.'/start-travel')->assertOk();
        $this->postJson($path.$visit->id.'/arrive')->assertOk();
        return $this->getJson($path.$visit->id.'/security-code')->assertOk()->json('data.securityCode');
    });

    Sanctum::actingAs($customer);
    $verify = $path.$visits[0]->id.'/start-verification/confirm';
    $invalid = $codes[0] === '9999' ? '9998' : '9999';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson($verify, ['code' => $invalid])->assertUnprocessable();
    }
    $this->postJson($verify, ['code' => $codes[0]])->assertStatus(429);
    expect($visits[0]->workerAssignments()->value('start_approved_at'))->toBeNull();
    $this->postJson($path.$visits[1]->id.'/start-verification/confirm', ['code' => $codes[1]])->assertOk();
    expect($visits[1]->workerAssignments()->value('start_approved_at'))->not->toBeNull();
});
