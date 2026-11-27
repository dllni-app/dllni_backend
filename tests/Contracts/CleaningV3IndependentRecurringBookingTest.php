<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\User;
use Database\Seeders\CleaningV3QaCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3ConfirmedVisitPrice;
use Modules\Cleaning\Services\CleaningV3OperationalAdmissionReview;
use Modules\Cleaning\Services\CleaningV3OperationalApprovalService;
use Modules\Cleaning\Services\CleaningV3QaEnvironment;
use Modules\Cleaning\Services\CleaningV3WeeklyRecurrencePreviewService;
use Modules\Cleaning\Services\CleaningV3PendingRevisionService;
use Spatie\Permission\Models\Role;

uses(Tests\TestCase::class);

beforeEach(function (): void {
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

test('three recurring days are independent signed bookings with isolated admission and stable replay', function (bool $withCoupon): void {
    // Opt-in evidence export: only approved contract fields from real authenticated
    // QA responses. Never export the quote signature, code, roster PII or GPS.
    $fixtures = [];
    $capture = static function (string $name, \Illuminate\Testing\TestResponse $response, array $keys) use (&$fixtures): \Illuminate\Testing\TestResponse {
        $data = array_intersect_key($response->json('data'), array_flip($keys));
        if (isset($data['dailyBooking'])) {
            $data['dailyBooking'] = array_intersect_key($data['dailyBooking'], array_flip([
                'bookingId', 'executionId', 'status', 'state', 'scheduledDate', 'scheduledTime',
                'durationMinutes', 'requiredWorkers', 'acceptedWorkers', 'remainingWorkers',
                'dispatchStatus', 'searchActive', 'clock', 'allowedActions', 'priceRevision', 'serverNow',
            ]));
            if (isset($data['dailyBooking']['clock'])) {
                $data['dailyBooking']['clock'] = array_intersect_key($data['dailyBooking']['clock'], array_flip([
                    'state', 'revision', 'serverNow', 'activeSeconds', 'activeSince',
                    'pausePending', 'pendingPauseRequestId', 'visitId', 'canSettle',
                ]));
            }
        }
        foreach ([
            'bookings' => ['bookingId', 'bookingNumber', 'occurrenceKey', 'appointmentKey', 'scheduledDate',
                'scheduledTime', 'amount', 'visitId', 'priceRevision', 'admissionStatus'],
            'visits' => ['visitId', 'occurrenceKey', 'appointmentKey', 'priceRevision'],
            'requirements' => ['lineId', 'itemKey', 'scopeKey', 'label', 'measurementMode', 'quantity', 'effortMinutes'],
        ] as $field => $allowed) {
            if (isset($data[$field])) {
                $data[$field] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip($allowed)), $data[$field]);
            }
        }
        if (isset($data['myMembership'])) {
            $data['myMembership'] = array_intersect_key($data['myMembership'], array_flip(['executionId', 'sessionAssignmentId', 'status']));
        }
        $fixtures[$name] = ['httpStatus' => $response->getStatusCode(), 'body' => ['data' => $data]];

        return $response;
    };
    $receiptFields = ['receiptKind', 'confirmationQuoteId', 'bookingId', 'bookingNumber', 'paymentStatus',
        'admissionStatus', 'canDispatch', 'replayed', 'bookings', 'visits'];
    $dailyFields = ['dailyBooking'];
    $workerFields = ['dailyBooking', 'requirementsAvailable', 'requirements', 'myMembership', 'contractVersion'];
    // Exact clock assertions must not depend on crossing a wall-clock second
    // while the real MySQL requests and rollback checks execute.
    $this->freezeSecond();
    config()->set([
        'cleaning_v3_catalog.admin_publication_enabled' => true,
        'cleaning_v3_catalog.read_enabled' => true,
        'cleaning_v3_catalog.cash_quote_enabled' => true,
        'cleaning_v3_catalog.new_quote_archive_enabled' => true,
        'cleaning_v3_catalog.new_booking_admission_enabled' => true,
        'cleaning_v3_catalog.independent_daily_bookings_enabled' => true,
        'cleaning_v3_catalog.operational_approval_enabled' => true,
        'cleaning_v3_catalog.qa_enabled' => true,
        'cleaning_v3_catalog.qa_signing_key' => bin2hex(random_bytes(32)),
    ]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);
    app()->call([app(CleaningV3QaCatalogSeeder::class), 'run']);
    $catalog = CleaningV3CatalogVersion::query()->where('version_key', CleaningV3QaEnvironment::CATALOG_KEY)->sole();
    ($settings = CleaningFinancialSetting::query()->orderBy('id')->first() ?? new CleaningFinancialSetting)->fill([
        ...\Modules\Cleaning\Support\CleaningRuntimeSettings::financialDefaults(),
        'travel_markup_value' => 1000, 'cleaning_minimum_order_price' => 1000,
        'v3_supervisor_visit_compensation' => 15000,
    ])->saveQuietly();
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $customer->id, 'street' => 'QA street', 'latitude' => '36.20', 'longitude' => '37.15',
    ]);
    $addressRevision = $this->getJson('/api/v1/cleaning/v3/customer-preferences')->assertOk()->json('data.addresses.0.revision');
    $rule = [
        'seriesKey' => 'independent-qa', 'catalogVersionKey' => $catalog->version_key,
        'catalogRevision' => $catalog->published_digest,
        'startDate' => now('Asia/Damascus')->addDays(2)->toDateString(),
        'startTime' => '09:00', 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'intervalWeeks' => 1, 'occurrenceCount' => 3, 'durationMinutes' => 150,
        'requiredWorkers' => 2, 'exceptions' => [],
    ];
    $plan = app(CleaningV3WeeklyRecurrencePreviewService::class)->preview($rule);
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $catalog->version_key,
        'catalogRevision' => $catalog->published_digest, 'cleaningMode' => 'regular',
        'answers' => ['qa.room' => 2], 'pricingPreviewRequested' => true,
        'preferences' => ['addressId' => $address->id, 'addressRevision' => $addressRevision,
            'genderPreference' => 'any', 'excludedWorkerIds' => []],
        'recurrenceRule' => $rule,
        'appointments' => array_map(fn ($appointment) => [
            'appointmentKey' => $appointment['appointmentKey'],
            'visits' => array_map(fn ($v) => array_intersect_key($v, array_flip([
                'occurrenceKey', 'scheduledDate', 'scheduledTime', 'durationMinutes', 'requiredWorkers',
            ])), $appointment['visits']),
        ], $plan['appointments']),
    ];
    $coupon = null;
    if ($withCoupon) {
        $coupon = \App\Models\PlatformCoupon::query()->create([
            'code' => 'DAILY'.Str::upper(Str::random(8)), 'title_ar' => 'QA coupon', 'description_ar' => 'QA coupon test', 'section' => 'cleaning',
            'discount_type' => 'fixed', 'discount_value' => 20000,
            'audience_type' => 'all_users', 'is_active' => true, 'total_usage_limit' => 1,
            'per_user_usage_limit' => 1, 'funding_type' => 'platform', 'cap_platform_funding_to_revenue' => true,
        ]);
        $form['couponCode'] = $coupon->code;
    }
    $form['expectedPricingFingerprint'] = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)
        ->assertOk()->json('data.commercialPricePreview.pricingFingerprint');
    $quote = $this->postJson('/api/v1/cleaning/v3/quotes', [...$form, 'idempotencyKey' => (string) Str::uuid()])
        ->assertOk()->json('data');
    expect($quote['snapshot']['dailyAppointments'])->toHaveCount(3);
    expect($quote['snapshot']['workRequirementsDigests'])->toHaveCount(3);
    $confirmation = [
        'quoteId' => $quote['quoteId'], 'idempotencyKey' => $quote['idempotencyKey'],
        'requestHash' => $quote['requestHash'], 'quoteSignature' => $quote['signature'],
        'acceptedTotalSyp' => $quote['snapshot']['totalWithAdditionalWorkerFees'],
        'pricingRevision' => $quote['snapshot']['pricingRevision'],
        'pricingFingerprint' => $quote['snapshot']['commercialReview']['pricingFingerprint'],
        'draftFingerprint' => $quote['snapshot']['commercialReview']['draftFingerprint'],
    ];
    $payload = ['confirmation' => $confirmation, 'draft' => $form, 'termsAccepted' => true];
    // Inject a failure only after the third actual quote-link INSERT. This
    // checks rollback of already-written booking, execution and resource rows
    // rather than merely rejecting invalid input before the first write.
    $armed = true;
    $linkWrites = 0;
    $beforeBookings = CleaningBooking::query()->where('customer_id', $customer->id)->count();
    $beforeSessions = DB::table('cleaning_booking_sessions')->count();
    $beforeEquipment = DB::table('cleaning_equipment_reservations')->count();
    $beforeRedemptions = DB::table('platform_coupon_redemptions')->count();
    DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$armed, &$linkWrites, $quote): void {
        if ($armed && preg_match('/^insert into [`"]?cleaning_quote_bookings[`"]?\s/i', $query->sql)
            && in_array($quote['quoteId'], $query->bindings, true) && ++$linkWrites === 3) {
            throw new DomainException('QA_DAILY_THIRD_LINK_WRITE_FAILED');
        }
    });
    try {
        $this->postJson('/api/v1/cleaning/v3/bookings', $payload)->assertConflict()
            ->assertJsonPath('code', 'QA_DAILY_THIRD_LINK_WRITE_FAILED');
    } finally {
        $armed = false;
    }
    expect($linkWrites)->toBe(3)
        ->and(CleaningBooking::query()->where('customer_id', $customer->id)->count())->toBe($beforeBookings)
        ->and(DB::table('cleaning_booking_sessions')->count())->toBe($beforeSessions)
        ->and(DB::table('cleaning_equipment_reservations')->count())->toBe($beforeEquipment)
        ->and(DB::table('platform_coupon_redemptions')->count())->toBe($beforeRedemptions)
        ->and(DB::table('cleaning_quote_bookings')->where('quote_id', $quote['quoteId'])->count())->toBe(0)
        ->and(DB::table('cleaning_booking_quotes')->where('id', $quote['quoteId'])->value('cleaning_booking_id'))->toBeNull();
    $response = $capture('customer.created', $this->postJson('/api/v1/cleaning/v3/bookings', $payload)->assertCreated(), $receiptFields)->json('data');
    $items = collect($response['bookings']);
    expect($response['receiptKind'])->toBe('independent_daily')
        ->and($response['confirmationQuoteId'])->toBe($quote['quoteId'])
        ->and($items->pluck('priceRevision')->unique()->all())->toBe([$confirmation['pricingRevision']]);
    expect($items)->toHaveCount(3)
        ->and($items->pluck('bookingId')->unique())->toHaveCount(3)
        ->and($items->pluck('bookingNumber')->unique())->toHaveCount(3)
        ->and($items->pluck('visitId')->unique())->toHaveCount(3)
        ->and($response['visits'])->toHaveCount(1)
        ->and(DB::table('cleaning_quote_bookings')->where('quote_id', $quote['quoteId'])->count())->toBe(3);
    $sum = '0.00';
    foreach ($items as $item) {
        $booking = CleaningBooking::query()->findOrFail($item['bookingId']);
        $visit = $booking->sessions()->sole();
        expect((int) $booking->customer_id)->toBe((int) $customer->id)
            ->and($visit->id)->toBe($item['visitId'])
            ->and(bccomp((string) $booking->getRawOriginal('total_price'), $item['amount'], 2))->toBe(0)
            ->and($booking->scheduled_date->format('Y-m-d'))->toBe($item['scheduledDate'])
            ->and($visit->sequence)->toBe(1);
        $sum = bcadd($sum, $item['amount'], 2);
        [$resolved, , $row] = app(CleaningV3ConfirmedVisitPrice::class)->load($booking, $visit);
        expect($resolved->id)->toBe($quote['quoteId'])->and($row['occurrenceKey'])->toBe($item['occurrenceKey']);
    }
    expect($sum)->toBe($confirmation['acceptedTotalSyp']);
    $this->postJson('/api/v1/cleaning/v3/bookings', $payload)->assertOk()->assertJsonPath('data.bookings', $items->all());
    $ids = $items->pluck('bookingId')->all();
    $first = CleaningBooking::query()->findOrFail($ids[0]);
    $second = CleaningBooking::query()->findOrFail($ids[1]);
    expect(fn () => app(CleaningV3ConfirmedVisitPrice::class)->load($first, $second->sessions()->sole()))
        ->toThrow(DomainException::class);
    if ($withCoupon) {
        $redemptions = DB::table('platform_coupon_redemptions')->where('platform_coupon_id', $coupon->id);
        expect($redemptions->count())->toBe(1);
        foreach (array_reverse($ids) as $index => $id) {
            $this->postJson('/api/v1/user/cleaning/orders/'.$id.'/cancel', ['reason' => 'QA'])->assertOk();
            expect((clone $redemptions)->whereNull('reversed_at')->count())->toBe($index === 2 ? 0 : 1);
        }
        $this->postJson('/api/v1/user/cleaning/orders/'.$ids[0].'/cancel', ['reason' => 'QA'])->assertOk();
        expect($redemptions->count())->toBe(1);
        $replay = $this->postJson('/api/v1/cleaning/v3/bookings', $payload)->assertOk()->json('data.bookings');
        expect(array_column($replay, 'bookingId'))->toBe($ids)
            ->and(array_column($replay, 'amount'))->toBe($items->pluck('amount')->all());
        return;
    }
    [$revisionBooking, $revisionVisits] = app(CleaningV3PendingRevisionService::class)->lock((int) $customer->id, $ids[1], 1);
    expect($revisionBooking->id)->toBe($ids[1])->and($revisionVisits)->toHaveCount(1);
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson('/api/v1/cleaning/v3/bookings', $payload)->assertStatus(409);
    Sanctum::actingAs($admin);
    $review = app(CleaningV3OperationalAdmissionReview::class)->review($ids[0]);
    expect($review['signedPriceVerified'])->toBeTrue()->and($review['visitCount'])->toBe(1)->and($review['canDispatch'])->toBeTrue();
    $approval = app(CleaningV3OperationalApprovalService::class)->approve($ids[0]);
    expect($approval['approved'])->toBeTrue()->and($approval['visitIds'])->toBe([$items[0]['visitId']]);
    expect(DB::table('cleaning_v3_visit_dispatches')->whereIn('cleaning_booking_session_id', $items->pluck('visitId'))->count())->toBe(1);
    expect(data_get($second->fresh()->property_details, 'v3_admission_hold'))->toBeTrue();
    $report = app(\Modules\Cleaning\Services\CleaningOperationsReportingService::class);
    expect($report->searchingBookings())->toBeGreaterThanOrEqual(1);
    $board = \App\Filament\Resources\CleaningV3VisitDispatches\CleaningV3VisitDispatchResource::getEloquentQuery()
        ->whereIn('cleaning_booking_session_id', $items->pluck('visitId'))->get();
    expect($board)->toHaveCount(1)
        ->and((int) $board->first()->cleaning_booking_session_id)->toBe($items[0]['visitId']);
    Sanctum::actingAs($customer);
    $capture('customer.searching', $this->getJson('/api/v1/user/cleaning/orders/'.$ids[0])->assertOk()
        ->assertJsonPath('data.dailyBooking.state', 'searching')
        ->assertJsonPath('data.dailyBooking.searchActive', true)
        ->assertJsonPath('data.createdFromRecurrence', true)
        ->assertJsonPath('data.isRecurringOrMultiVisit', false), [...$dailyFields, 'createdFromRecurrence', 'isRecurringOrMultiVisit']);
    $capture('customer.held', $this->getJson('/api/v1/cleaning-bookings/'.$ids[1].'/schedule')->assertOk()
        ->assertJsonPath('data.dailyBooking.executionId', $items[1]['visitId'])
        ->assertJsonPath('data.dailyBooking.state', 'pending_operational_review')
        ->assertJsonPath('data.dailyBooking.allowedActions', ['cancel_booking'])
        ->assertJsonCount(1, 'data.schedule.sessions'), $dailyFields);
    $this->postJson('/api/v1/user/cleaning/orders/'.$ids[0].'/cancel', ['reason' => 'QA'])->assertStatus(409);
    $this->postJson('/api/v1/user/cleaning/orders/'.$ids[2].'/cancel', ['reason' => 'QA'])->assertOk()
        ->assertJsonPath('order.dailyBooking.state', 'cancelled')
        ->assertJsonPath('order.dailyBooking.searchActive', false);
    expect($first->fresh()->status->value)->toBe('pending')
        ->and($second->fresh()->status->value)->toBe('pending')
        ->and(CleaningBooking::findOrFail($ids[2])->sessions()->sole()->status->value)->toBe('cancelled');
    $replay = $capture('customer.replayed', $this->postJson('/api/v1/cleaning/v3/bookings', $payload)->assertOk(), $receiptFields)->json('data.bookings');
    expect(array_column($replay, 'bookingId'))->toBe($ids)
        ->and(array_column($replay, 'amount'))->toBe($items->pluck('amount')->all())
        ->and(array_column($replay, 'priceRevision'))->toBe($items->pluck('priceRevision')->all())
        ->and(array_column($replay, 'admissionStatus'))->toBe(['approved', 'pending_operational_review', 'cancelled']);
    $draft = $this->getJson('/api/v1/cleaning/v3/bookings/'.$ids[1].'/revision-draft')->assertOk()->json('data');
    expect($draft['draft'])->not->toHaveKey('recurrenceRule')
        ->and($draft['draft']['appointments'])->toHaveCount(1);
    Sanctum::actingAs($admin);
    // Persisted occurrence tampering must not grant access to the sibling's signed allocation.
    $visit = $second->sessions()->sole();
    $visit->forceFill(['occurrence_key' => $items[0]['occurrenceKey']])->saveQuietly();
    expect(app(CleaningV3OperationalAdmissionReview::class)->review($ids[1])['signedPriceVerified'])->toBeFalse();
    $visit->forceFill(['occurrence_key' => $items[1]['occurrenceKey']])->saveQuietly();
    Sanctum::actingAs($customer);
    $amend = $draft['draft'];
    $amend['appointments'][0]['visits'][0]['durationMinutes'] = 180;
    $amend['expectedPricingFingerprint'] = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $amend)
        ->assertOk()->json('data.commercialPricePreview.pricingFingerprint');
    $revised = $this->postJson('/api/v1/cleaning/v3/quotes', [...$amend, 'revisionBookingId' => $ids[1],
        'expectedPriceRevision' => 1, 'idempotencyKey' => (string) Str::uuid()])->assertOk()->json('data');
    $revisionConfirmation = [
        'quoteId' => $revised['quoteId'], 'idempotencyKey' => $revised['idempotencyKey'],
        'requestHash' => $revised['requestHash'], 'quoteSignature' => $revised['signature'],
        'acceptedTotalSyp' => $revised['snapshot']['totalWithAdditionalWorkerFees'],
        'pricingRevision' => $revised['snapshot']['pricingRevision'],
        'pricingFingerprint' => $revised['snapshot']['commercialReview']['pricingFingerprint'],
        'draftFingerprint' => $revised['snapshot']['commercialReview']['draftFingerprint'],
    ];
    $this->postJson('/api/v1/cleaning/v3/bookings', ['confirmation' => $revisionConfirmation,
        'draft' => $amend, 'termsAccepted' => true])->assertCreated()->assertJsonPath('data.bookingId', $ids[1]);
    expect($visit->fresh()->price_revision)->toBe(2)
        ->and((float) $visit->fresh()->duration_hours)->toBe(3.0)
        ->and($first->sessions()->sole()->price_revision)->toBe(1);
    $replay = $this->postJson('/api/v1/cleaning/v3/bookings', $payload)->assertOk()->json('data.bookings');
    expect(array_column($replay, 'bookingId'))->toBe($ids)
        ->and(array_column($replay, 'amount'))->toBe($items->pluck('amount')->all());

    // End-to-end signed daily execution, isolated from the amended and
    // cancelled siblings. All writes remain in the test's MySQL transaction.
    $day = $first->sessions()->sole();
    $sibling = $second->fresh();
    $siblingVisit = $sibling->sessions()->sole();
    $siblingBefore = $siblingVisit->getAttributes();
    $cashBefore = DB::table('cleaning_v3_cash_movements')->count();
    $workerUsers = [];
    for ($index = 0; $index < 2; $index++) {
        $workerUser = User::factory()->create(['is_active' => true]);
        \App\Models\Worker::factory()->financiallyEligible()->create([
            'user_id' => $workerUser->id, 'trust_score' => 100,
            'home_latitude' => 36.20, 'home_longitude' => 37.15,
        ]);
        $workerUsers[] = $workerUser;
    }
    $base = '/api/v1/cleaning-bookings/'.$first->id;
    $teamDetail = '/api/v1/cleaning/supervised-worker/bookings/'.$first->id;
    $visitUrl = $base.'/sessions/'.$day->id;
    $supervisorUrl = '/api/v1/supervisor/bookings/'.$first->id;
    $supervisorVisit = $supervisorUrl.'/visits/'.$day->id;
    $customerCode = '/api/v1/supervised-team/customer/bookings/'.$first->id.'/visits/'.$day->id.'/verification-code';
    $this->withHeaders([
        \Modules\Cleaning\Http\Middleware\RejectUnapprovedV3WorkerLifecycle::HEADER
            => \Modules\Cleaning\Http\Middleware\RejectUnapprovedV3WorkerLifecycle::CAPABILITY,
    ]);
    Sanctum::actingAs($workerUsers[0]);
    $this->getJson($teamDetail)->assertNotFound();
    $offer = $capture('worker.offer', $this->getJson($base)->assertOk()
        ->assertJsonPath('data.dailyBooking.executionId', $day->id)
        ->assertJsonPath('data.dailyBooking.state', 'searching')
        ->assertJsonPath('data.dailyBooking.allowedActions', ['accept'])
        ->assertJsonMissingPath('data.addressLatitude')
        ->assertJsonMissingPath('data.customer')
        ->assertJsonPath('data.requirementsAvailable', true)
        ->assertJsonPath('data.requirements.0.itemKey', 'qa.room')
        ->assertJsonMissingPath('data.requirements.0.estimatedBaseAmountSyp'), $workerFields)
        ->json('data.requirements');
    $savedPricing = $day->pricing_snapshot;
    $tampered = $savedPricing;
    $tampered['taskGroups'][0]['quantity'] = '999.00';
    $day->forceFill(['pricing_snapshot' => $tampered])->saveQuietly();
    $capture('worker.requirements_unavailable', $this->getJson($base)->assertOk()
        ->assertJsonPath('data.requirementsAvailable', false)
        ->assertJsonPath('data.requirements', null), $workerFields);
    $day->forceFill(['pricing_snapshot' => $savedPricing])->saveQuietly();
    $this->postJson($base.'/sessions/accept-selected', ['sessionIds' => [$day->id]])
        ->assertOk()->assertJsonPath('data.acceptance.acceptedSessionIds', [$day->id]);
    $this->getJson($base)->assertOk()->assertJsonPath('data.dailyBooking.executionId', $day->id)
        ->assertJsonPath('data.dailyBooking.acceptedWorkers', 1);
    $ownAssignment = $day->workerAssignments()->where('worker_id', $workerUsers[0]->worker->id)->sole();
    $capture('worker.accepted', $this->getJson($teamDetail)->assertOk()->assertJsonPath('data.contractVersion', 3)
        ->assertJsonPath('data.myMembership.executionId', $day->id)
        ->assertJsonPath('data.myMembership.sessionAssignmentId', $ownAssignment->id)
        ->assertJsonPath('data.destination', null)
        ->assertJsonPath('data.myAttendance.canStartTravel', false)
        ->assertJsonPath('data.requirementsAvailable', true)
        ->assertJsonPath('data.requirements', $offer)
        ->assertJsonMissingPath('data.myMembership.assignmentId')
        ->assertJsonMissingPath('data.customer'), $workerFields);
    $this->getJson($teamDetail.'?sessionId='.$siblingVisit->id)->assertNotFound();
    $this->getJson('/api/v1/cleaning-bookings/'.$sibling->id)->assertNotFound();
    $this->getJson('/api/v1/cleaning-bookings/'.$sibling->id.'/schedule')->assertForbidden();
    $this->postJson($base.'/sessions/accept-selected', ['sessionIds' => [$siblingVisit->id]])
        ->assertOk()->assertJsonPath('data.acceptance.acceptedSessionIds', []);
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole(Role::findOrCreate(\Modules\Cleaning\Services\SupervisedTeamService::ROLE, 'web'));
    $outsider = User::factory()->create(['is_active' => true]);
    $outsider->assignRole(Role::findOrCreate(\Modules\Cleaning\Services\SupervisedTeamService::ROLE, 'web'));
    Sanctum::actingAs($admin);
    app(\Modules\Cleaning\Services\CleaningSupervisorAssignmentService::class)
        ->assign($admin, $first->id, $supervisor->id, null, true);
    Sanctum::actingAs($supervisor);
    $capture('supervisor.partial_crew', $this->getJson($supervisorUrl)->assertOk()->assertJsonPath('data.requiredWorkers', 2)
        ->assertJsonPath('data.acceptedWorkers', 1)->assertJsonPath('data.durationMinutes', 150)
        ->assertJsonPath('data.dailyBooking.executionId', $day->id)
        ->assertJsonPath('data.dailyBooking.allowedActions', []), $dailyFields);
    $this->getJson('/api/v1/supervisor/bookings/'.$sibling->id)->assertNotFound();
    Sanctum::actingAs($outsider);
    $this->getJson($supervisorUrl)->assertNotFound();

    Sanctum::actingAs($workerUsers[1]);
    $this->getJson($teamDetail)->assertNotFound();
    $this->postJson($base.'/sessions/accept-selected', ['sessionIds' => [$day->id]])->assertOk()
        ->assertJsonPath('data.acceptance.acceptedSessionIds', [$day->id]);
    foreach ($workerUsers as $workerUser) {
        Sanctum::actingAs($workerUser);
        $this->getJson($teamDetail.'?sessionId='.$day->id)->assertOk()->assertJsonPath('data.destination', null);
        $this->postJson($visitUrl.'/start-travel')->assertOk()
            ->assertJsonMissingPath('data.totalPrice')->assertJsonMissingPath('data.reviewTarget');
        $this->getJson($teamDetail)->assertOk()->assertJsonPath('data.destination', null);
        $this->getJson($teamDetail.'?sessionId='.$day->id)->assertOk()
            ->assertJsonPath('data.destination.latitude', 36.2)->assertJsonPath('data.destination.longitude', 37.15);
        $this->postJson($visitUrl.'/arrive')->assertOk()
            ->assertJsonMissingPath('data.totalPrice')->assertJsonMissingPath('data.reviewTarget');
        $this->getJson($teamDetail.'?sessionId='.$day->id)->assertOk()->assertJsonPath('data.destination', null);
    }
    Sanctum::actingAs($supervisor);
    $capture('supervisor.arrived', $this->getJson($supervisorUrl)->assertOk()->assertJsonPath('data.acceptedWorkers', 2)
        ->assertJsonPath('data.physicallyArrivedWorkers', 2)
        ->assertJsonPath('data.dailyBooking.searchActive', false)
        ->assertJsonPath('data.dailyBooking.allowedActions', ['request_verification']), $dailyFields);
    $this->postJson($supervisorVisit.'/verification-requests')->assertOk()->assertJsonPath('data.status', 'pending');
    $capture('supervisor.code_pending', $this->getJson($supervisorUrl)->assertOk()->assertJsonPath('data.v3Visits.0.verificationStatus', 'pending')
        ->assertJsonPath('data.dailyBooking.allowedActions', ['confirm_verification']), $dailyFields);
    Sanctum::actingAs($customer);
    $this->getJson($base.'/schedule')->assertOk()->assertJsonPath('data.schedule.sessions.0.canViewSupervisorCode', true);
    $code = $this->getJson($customerCode)->assertOk()->json('data.code');
    Sanctum::actingAs($supervisor);
    $this->postJson($supervisorVisit.'/verification/confirm', ['code' => $code])->assertOk();
    $capture('supervisor.verified', $this->getJson($supervisorUrl)->assertOk()->assertJsonPath('data.dailyBooking.allowedActions', ['start_work']), $dailyFields);
    $this->postJson($supervisorVisit.'/start')->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->travel(2)->minutes();
    Sanctum::actingAs($customer);
    $capture('customer.running', $this->getJson($base.'/schedule')->assertOk()
        ->assertJsonPath('data.dailyBooking.state', 'in_progress')
        ->assertJsonPath('data.dailyBooking.clock.activeSeconds', 120), $dailyFields);
    $pause = $this->postJson($visitUrl.'/pause-requests', [
        'commandKey' => 'independent-pause', 'expectedRevision' => 1,
    ])->assertOk()->json('pauseRequestId');
    Sanctum::actingAs($supervisor);
    $capture('supervisor.pause_pending', $this->getJson($supervisorUrl)->assertOk()
        ->assertJsonPath('data.dailyBooking.allowedActions', ['approve_pause', 'reject_pause', 'request_handover']), $dailyFields);
    $this->postJson($visitUrl.'/pause-requests/'.$pause.'/decision', [
        'commandKey' => 'independent-pause-approved', 'expectedRevision' => 2, 'decision' => 'approve',
    ])->assertOk()->assertJsonPath('data.state', 'paused');
    $this->travel(1)->minutes();
    $capture('supervisor.paused', $this->getJson($supervisorUrl)->assertOk()->assertJsonPath('data.dailyBooking.clock.activeSeconds', 120)
        ->assertJsonPath('data.dailyBooking.state', 'paused')
        ->assertJsonPath('data.dailyBooking.allowedActions', ['resume_work']), $dailyFields);
    $this->postJson($visitUrl.'/resume', ['commandKey' => 'independent-resume', 'expectedRevision' => 3])
        ->assertOk()->assertJsonPath('data.state', 'running');
    $this->travel(1)->minutes();
    $this->postJson($supervisorVisit.'/handover-requests')->assertOk();
    Sanctum::actingAs($customer);
    $this->postJson($visitUrl.'/completion/confirm')->assertOk()
        ->assertJsonPath('data.reviewTarget.sessionId', $day->id)
        ->assertJsonCount(2, 'data.reviewTarget.workerIds')
        ->assertJsonStructure(['data' => ['totalPrice']]);
    $capture('customer.completed', $this->getJson($base.'/schedule')->assertOk()->assertJsonPath('data.dailyBooking.state', 'completed')
        ->assertJsonPath('data.dailyBooking.searchActive', false)
        ->assertJsonPath('data.dailyBooking.clock.activeSeconds', 180), $dailyFields);
    expect($day->fresh()->status->value)->toBe('completed')
        ->and($day->fresh()->payment_status)->toBe('pending')
        ->and($siblingVisit->fresh()->getAttributes())->toBe($siblingBefore)
        ->and(CleaningBooking::findOrFail($ids[2])->status->value)->toBe('cancelled')
        ->and(DB::table('cleaning_v3_cash_movements')->count())->toBe($cashBefore)
        ->and(DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $day->id)->count())->toBe(2)
        ->and(DB::table('cleaning_work_intervals')->where('active_session_id', $day->id)->exists())->toBeFalse();

    // Exercise the real signed daily cash/accounting path in this rollback-only
    // QA transaction. Evidence strings represent simulated receipts, never
    // physical receipts or production ledger entries.
    config()->set('cleaning_v3_catalog.cash_settlement_enabled', true);
    $accounting = app(\Modules\Cleaning\Services\CleaningV3SupervisorCashAccountingService::class);
    $settlement = app(\Modules\Cleaning\Services\CleaningV3CashSettlementService::class);
    $proof = app(\Modules\Cleaning\Services\CleaningV3SupervisorAttendanceProofService::class);
    $reviewer = User::factory()->create(['is_active' => true]);
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $reference = 'daily-qa-'.Str::uuid();
    $evidence = 'Isolated rollback-only QA simulated evidence';
    Sanctum::actingAs($admin);
    expect(fn () => $settlement->review($first->id, $day->id))
        ->toThrow(DomainException::class, 'V3_SUPERVISOR_ATTENDANCE_PROOF_REQUIRED');
    $intervals = DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $day->id)
        ->orderBy('started_at')->get();
    $from = \Carbon\CarbonImmutable::parse($intervals->first()->started_at, 'UTC')->format('Y-m-d\\TH:i:s\\Z');
    $until = \Carbon\CarbonImmutable::parse($intervals->last()->ended_at, 'UTC')->format('Y-m-d\\TH:i:s\\Z');
    $facts = $proof->record($first->id, $day->id, $supervisor->id, $from, $until, $reference.'-attendance', $evidence);
    expect($facts['active_seconds'])->toBe(180);
    expect(fn () => $proof->verify($first->id, $day->id, $reference.'-attendance', 180))
        ->toThrow(DomainException::class, 'V3_SUPERVISOR_ATTENDANCE_INDEPENDENT_VERIFICATION_REQUIRED');
    Sanctum::actingAs($reviewer);
    $proof->verify($first->id, $day->id, $reference.'-attendance', 180);
    $state = $settlement->review($first->id, $day->id);
    expect($state['status'])->toBe('awaiting_report')
        ->and($state['customerAmountSyp'])->toBe($items[0]['amount'])
        ->and(bcadd(bcadd($state['workerAmountSyp'], $state['supervisorAmountSyp'], 2), $state['companyAmountSyp'], 2))->toBe($state['customerAmountSyp']);
    expect(fn () => $accounting->receive($first->id, $day->id, $state['companyAmountSyp'], $reference.'-receipt', $evidence))
        ->toThrow(DomainException::class, 'V3_SUPERVISOR_CASH_RECEIPT_MISMATCH');
    $cashUrl = $supervisorVisit.'/cash-accounting';
    $cashPayload = ['collectedAmountSyp' => $state['customerAmountSyp'], 'reference' => $reference.'-report',
        'evidence' => $evidence, 'cashReceivedAndPayablesPaid' => true];
    Sanctum::actingAs($outsider, ['supervisor']);
    $this->postJson($cashUrl, $cashPayload)->assertNotFound();
    Sanctum::actingAs($supervisor, ['supervisor']);
    $this->getJson($cashUrl)->assertOk()->assertJsonPath('data.status', 'awaiting_report');
    $this->postJson($cashUrl, [...$cashPayload, 'cashReceivedAndPayablesPaid' => false])->assertUnprocessable();
    $this->postJson($cashUrl, $cashPayload)->assertOk()->assertJsonPath('data.status', 'reported');
    $this->postJson($cashUrl, $cashPayload)->assertOk()->assertJsonPath('data.status', 'reported');
    expect($day->fresh()->payment_status)->toBe('pending');
    expect(fn () => $accounting->receive($first->id, $day->id, $state['companyAmountSyp'], $reference.'-receipt', $evidence))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Sanctum::actingAs($reviewer);
    $accounting->receive($first->id, $day->id, $state['companyAmountSyp'], $reference.'-receipt', $evidence);
    $accounting->receive($first->id, $day->id, $state['companyAmountSyp'], $reference.'-receipt', $evidence);
    $account = DB::table('cleaning_v3_supervisor_cash_accounts')->where('visit_id', $day->id)->sole();
    expect($day->fresh()->payment_status)->toBe('settled')
        ->and((int) $account->received_by)->toBe((int) $reviewer->id)
        ->and((int) $account->collector_user_id)->toBe((int) $supervisor->id)
        ->and(DB::table('cleaning_v3_supervisor_cash_earnings')->where('account_id', $account->id)->count())->toBe(1)
        ->and(DB::table('cleaning_v3_cash_movements')->count())->toBe($cashBefore)
        ->and($siblingVisit->fresh()->getAttributes())->toBe($siblingBefore);
    Sanctum::actingAs($supervisor, ['supervisor']);
    $this->getJson('/api/v1/supervisor/cash-accounting')->assertOk()
        ->assertJsonPath('data.companyOutstandingSyp', '0.00')
        ->assertJsonPath('data.companyDeliveredSyp', $state['companyAmountSyp'])
        ->assertJsonPath('data.ownConfirmedEarningsSyp', $state['supervisorAmountSyp']);
    Sanctum::actingAs($customer);
    $this->getJson($base.'/schedule')->assertOk()
        ->assertJsonPath('data.v3CashSettlements.0.collectedAmountSyp', $state['customerAmountSyp']);

    // A later amended day is independently admitted and reassigned without
    // rewriting the completed first day's assignment, accounting or history.
    $completedBefore = $day->fresh()->getAttributes();
    $completedAssignments = DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $first->id)->get()->all();
    Sanctum::actingAs($admin);
    expect(app(CleaningV3OperationalApprovalService::class)->approve($sibling->id)['approved'])->toBeTrue();
    $assign = app(\Modules\Cleaning\Services\CleaningSupervisorAssignmentService::class);
    $assign->assign($admin, $sibling->id, $supervisor->id, null, true);
    $secondDate = $sibling->scheduled_date->format('Y-m-d');
    Sanctum::actingAs($supervisor, ['supervisor']);
    $this->getJson('/api/v1/supervisor/schedule?date='.$secondDate)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sibling->id)
        ->assertJsonPath('data.0.durationMinutes', 180);
    Sanctum::actingAs($admin);
    $assign->assign($admin, $sibling->id, $outsider->id, 'Independent future day reassignment QA', true);
    Sanctum::actingAs($supervisor, ['supervisor']);
    $this->getJson('/api/v1/supervisor/bookings/'.$sibling->id)->assertNotFound();
    $this->getJson('/api/v1/supervisor/schedule?date='.$secondDate)->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($outsider, ['supervisor']);
    $this->getJson('/api/v1/supervisor/schedule?date='.$secondDate)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.dailyBooking.bookingId', $sibling->id);
    $this->getJson($supervisorUrl)->assertNotFound();
    expect($day->fresh()->getAttributes())->toBe($completedBefore)
        ->and(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $first->id)->get()->all())->toEqual($completedAssignments)
        ->and(DB::table('cleaning_v3_supervisor_cash_accounts')->where('visit_id', $day->id)->sole())->toEqual($account);
    $this->travelBack();
    if (getenv('CLEANING_V3_PHASE2_EXPORT_FIXTURES') === '1') {
        $directory = base_path('Plans/CleaningV3BookingCentric/contracts/fixtures/phase2-v1');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($directory);
        // Do not overwrite a previous evidence capture. Use a new filename on recapture.
        $path = $directory.'/independent-daily-'.now('UTC')->format('Ymd-His').'.json';
        $json = json_encode([
            'fixtureVersion' => 'phase2-v1-provisional',
            'source' => 'CleaningV3IndependentRecurringBookingTest authenticated rollback-only local MySQL requests',
            'capturedAt' => now('UTC')->toIso8601String(),
            'cases' => $fixtures,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create unique QA fixture file.');
        }
        try {
            if (fwrite($handle, $json.PHP_EOL) !== strlen($json.PHP_EOL)) {
                throw new RuntimeException('Could not write full QA fixture file.');
            }
        } finally {
            fclose($handle);
        }
    }
})->with([false, true]);
