<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningBookings\Pages\EditCleaningBooking;
use App\Filament\Resources\CleaningBookings\Pages\ListCleaningBookings;
use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\ArrivalVerified;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\ServiceExtensionRequested;
use Modules\Cleaning\Services\CleaningBookingPriceAdjustmentService;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Services\CleaningBookingWorkerCompletionService;
use Modules\Cleaning\Services\CleaningTimeWarningService;
use Modules\Cleaning\Services\CleaningBookingWorkerSecurityCodeService;
use Modules\User\Services\UserCleaningOrderService;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('database.connections.sqlite.url'))->toBe('')
        ->and(config('database.connections.sqlite.host'))->toBeNull()
        ->and(config('database.connections.sqlite.port'))->toBeNull()
        ->and(config('database.connections.sqlite.username'))->toBeNull()
        ->and(config('database.connections.sqlite.password'))->toBeNull()
        ->and(config('cache.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('broadcasting.default'))->toBeNull();
});

function persistPartialBooking(array $attributes = []): CleaningBooking
{
    $booking = CleaningBooking::factory()->create(array_merge([
        'status' => CleaningBookingStatus::Pending->value,
    ], $attributes));

    DB::table('cleaning_bookings')
        ->where('id', $booking->id)
        ->update(['status' => 'partially_completed']);

    return CleaningBooking::query()->findOrFail($booking->id);
}

it('reads persisted partial bookings for customers and accepted workers without enabling legacy actions', function (): void {
    $customer = User::factory()->create(['phone' => '+963900000001']);
    $workerUser = User::factory()->create(['name' => 'Accepted Worker']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $formerWorkerUser = User::factory()->create();
    $formerWorker = Worker::factory()->create(['user_id' => $formerWorkerUser->id]);
    $booking = persistPartialBooking([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now()->subMinute(),
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $formerWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
    ]);

    Sanctum::actingAs($customer);

    $this->getJson('/api/v1/cleaning-bookings')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'partially_completed');

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_completed')
        ->assertJsonPath('data.statusLabel', 'اكتمل جزئياً')
        ->assertJsonPath('data.completionRequest.actions.canConfirm', false)
        ->assertJsonPath('data.completionRequest.actions.canReject', false)
        ->assertJsonPath('data.completionRequest.actions.canRequestExtension', false);

    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.canEdit', false)
        ->assertJsonPath('data.can_edit', false);

    Sanctum::actingAs($workerUser);

    $this->getJson('/api/v1/cleaning-bookings')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'partially_completed');

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_completed')
        ->assertJsonPath('data.customer.phone', '+963900000001');

    // An accepted assignment grants the worker read access, while a pending offer does not.
    expect($booking->workerAssignments()->where('worker_id', $worker->id)->value('status'))
        ->toBe(CleaningBookingWorkerAssignmentStatus::Accepted);

    Sanctum::actingAs($formerWorkerUser);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertStatus(409);
});

it('keeps pending offer visibility separate from accepted worker privileges', function (): void {
    $customer = User::factory()->create(['phone' => '+963900000002']);
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = persistPartialBooking([
        'customer_id' => $customer->id,
        'worker_id' => null,
    ]);

    Sanctum::actingAs($workerUser);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertStatus(409);
});

it('keeps partial bookings visible to admins while blocking Filament edit and delete capabilities', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $booking = persistPartialBooking();

    $this->actingAs($admin)
        ->get(CleaningBookingResource::getUrl('index', isAbsolute: false))
        ->assertSuccessful()
        ->assertSee((string) $booking->booking_number);
    $this->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('اكتمل جزئياً');

    Livewire::test(ListCleaningBookings::class)
        ->filterTable('status', 'partially_completed')
        ->assertCanSeeTableRecords([$booking]);

    expect(CleaningBookingResource::canEdit($booking))->toBeFalse()
        ->and(CleaningBookingResource::canDelete($booking))->toBeFalse();

    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionHidden('cancel_booking');
    expect(DB::table('cleaning_bookings')->where('id', $booking->id)->value('status'))
        ->toBe('partially_completed');
});

it('rejects stale Filament edit submission after the stored record becomes partial', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $booking = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);
    $component = Livewire::actingAs($admin)->test(EditCleaningBooking::class, ['record' => $booking->getRouteKey()]);

    DB::table('cleaning_bookings')->where('id', $booking->id)->update(['status' => 'partially_completed']);
    $component->set('data.status', CleaningBookingStatus::Pending->value)->call('save');

    $staleView = CleaningBooking::factory()->create(['status' => CleaningBookingStatus::Pending->value]);
    $viewComponent = Livewire::actingAs($admin)->test(ViewCleaningBooking::class, ['record' => $staleView->getRouteKey()]);
    DB::table('cleaning_bookings')->where('id', $staleView->id)->update(['status' => 'partially_completed']);
    $viewComponent->callAction('cancel_booking', data: ['reason' => 'forged stale admin cancellation'])
        ->assertHasNoActionErrors();

    expect(DB::table('cleaning_bookings')->where('id', $booking->id)->value('status'))
        ->toBe('partially_completed')
        ->and(DB::table('cleaning_bookings')->where('id', $staleView->id)->value('status'))
        ->toBe('partially_completed');
});

it('retains the valid accepted-worker start verification flow', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now()->subMinute(),
        'arrived_at' => now(),
    ]);
    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $worker->id,
        'code' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $verified = app(CleaningBookingWorkerSecurityCodeService::class)->confirmForCustomer($booking, '1234');

    expect($verified->customer_confirmed_at)->not->toBeNull()
        ->and($verified->status)->toBe(CleaningBookingStatus::AwaitingWorkerStartConfirmation)
        ->and($booking->workerAssignments()->value('status'))
        ->toBe(CleaningBookingWorkerAssignmentStatus::StartApproved)
        ->and(DB::table('booking_security_codes')->where('booking_id', $booking->id)->value('consumed_at'))
        ->not->toBeNull();
});

it('retains legacy team recalculation for a non-partial booking', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $recalculated = app(CleaningBookingTeamService::class)->recalculateBookingTeam($booking);

    expect($recalculated->status)->toBe(CleaningBookingStatus::Pending);
});

it('allows active team assignment verification without downgrading in-progress assignment state', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
        'accepted_at' => now()->subHour(),
        'arrived_at' => now()->subMinutes(30),
        'start_approved_at' => now()->subMinutes(25),
        'work_started_at' => now()->subMinutes(20),
    ]);
    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $worker->id,
        'code' => hash_hmac('sha256', '4321', (string) config('app.key')),
        'code_hash' => hash_hmac('sha256', '4321', (string) config('app.key')),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $verified = app(CleaningBookingWorkerSecurityCodeService::class)->confirmForCustomer($booking, '4321');

    expect($verified->status)->toBe(CleaningBookingStatus::AwaitingCustomerCompletion)
        ->and($booking->workerAssignments()->value('status'))
        ->toBe(CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion);
});

it('allows start verification beside a sibling extension request without changing that decision', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $siblingUser = User::factory()->create();
    $sibling = Worker::factory()->create(['user_id' => $siblingUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::TimeExtensionRequested->value,
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
        'accepted_at' => now()->subHour(),
        'arrived_at' => now()->subMinute(),
    ]);
    $booking->workerAssignments()->create([
        'worker_id' => $sibling->id,
        'status' => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value,
        'accepted_at' => now()->subHour(),
        'arrived_at' => now()->subMinutes(20),
    ]);

    Sanctum::actingAs($workerUser);
    $issued = app(CleaningBookingWorkerSecurityCodeService::class)->issueForCurrentWorker($booking);
    $verified = app(CleaningBookingWorkerSecurityCodeService::class)->confirmForCustomer($booking, $issued['securityCode']);

    expect($verified->status)->toBe(CleaningBookingStatus::TimeExtensionRequested)
        ->and($booking->workerAssignments()->where('worker_id', $worker->id)->value('status'))
        ->toBe(CleaningBookingWorkerAssignmentStatus::StartApproved)
        ->and($booking->workerAssignments()->where('worker_id', $sibling->id)->value('status'))
        ->toBe(CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested)
        ->and(DB::table('booking_security_codes')->where('booking_id', $booking->id)->where('worker_id', $worker->id)->value('consumed_at'))
        ->not->toBeNull();
});

it('denies legacy room, edit, cancel and security code writes for a persisted partial booking', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = persistPartialBooking([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'arrived_at' => now(),
    ]);
    $room = CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom.large.1',
        'room_type' => 'bedroom',
        'room_size' => 'large',
        'display_label' => 'Bedroom 1',
        'weight' => 1,
    ]);
    $warning = CleaningTimeWarning::withoutEvents(fn () => CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
        'sent_at' => now(),
        'customer_responded_at' => now(),
        'additional_minutes' => 30,
        'quoted_base_amount' => 100,
        'quoted_admin_margin_amount' => 20,
        'quoted_amount' => 120,
        'quoted_currency' => 'SYP',
    ]));
    $adjustment = CleaningBookingPriceAdjustmentRequest::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'old_total_price' => 1000,
        'proposed_total_price' => 1200,
        'status' => CleaningPriceAdjustmentRequestStatus::Pending->value,
    ]);
    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $worker->id,
        'code' => '1234',
        'code_hash' => hash('sha256', '1234'),
        'attempts' => 3,
        'last_attempt_at' => now(),
        'consumed_at' => null,
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = DB::table('cleaning_bookings')->where('id', $booking->id)->first();
    $roomsBefore = DB::table('cleaning_booking_rooms')->where('cleaning_booking_id', $booking->id)->get()->map(fn (object $row): array => (array) $row)->all();
    $assignmentsBefore = DB::table('cleaning_booking_worker_assignments')->where('cleaning_booking_id', $booking->id)->get()->map(fn (object $row): array => (array) $row)->all();
    $securityCodeBefore = DB::table('booking_security_codes')->where('booking_id', $booking->id)->first();
    $warningBefore = DB::table('cleaning_time_warnings')->where('id', $warning->id)->first();
    $adjustmentBefore = DB::table('cleaning_booking_price_adjustment_requests')->where('id', $adjustment->id)->first();
    $sideEffectTablesBefore = [];
    foreach ([
        'cleaning_deposit_transactions',
        'platform_coupon_redemptions',
        'inventory_logs',
        'sm_inventory_logs',
    ] as $table) {
        $sideEffectTablesBefore[$table] = Schema::hasTable($table)
            ? DB::table($table)->count()
            : null;
    }
    $staleCaller = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);
    DB::table('cleaning_bookings')->where('id', $staleCaller->id)->update(['status' => 'partially_completed']);
    Event::fake([
        ArrivalVerified::class,
        CleaningBookingTrackingUpdated::class,
        ServiceExtensionRequested::class,
    ]);
    Notification::fake();

    expect(fn () => app(CleaningBookingTeamService::class)->syncRooms($staleCaller))
        ->toThrow(ValidationException::class);
    expect(DB::table('cleaning_bookings')->where('id', $staleCaller->id)->value('status'))
        ->toBe('partially_completed');

    expect(fn () => app(CleaningBookingTeamService::class)->syncRooms($booking))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CleaningBookingTeamService::class)->assignRoomsFromCustomer($booking, [[
        'roomId' => $room->id,
        'workerId' => $worker->id,
    ]]))->toThrow(ValidationException::class);
    expect(fn () => app(CleaningBookingTeamService::class)->recalculateBookingTeam($booking))
        ->toThrow(ValidationException::class);

    expect(fn () => app(CleaningBookingWorkerCompletionService::class)->confirm($booking))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CleaningBookingWorkerCompletionService::class)->reject($booking))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CleaningBookingWorkerCompletionService::class)->requestExtension($booking, 30))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CleaningTimeWarningService::class)->accept($warning))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(CleaningTimeWarningService::class)->reject($warning))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(CleaningBookingPriceAdjustmentService::class)->approve(
        $adjustment,
        1200,
        'pending approval must not change a partial booking',
        $customer,
    ))->toThrow(ValidationException::class);
    Sanctum::actingAs($customer);
    $this->patchJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'customerId' => $customer->id,
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
    ])->assertStatus(422);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/cancel", ['reason' => 'legacy cancellation'])
        ->assertStatus(422);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", ['code' => '1234'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
    $this->deleteJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertStatus(422);

    expect(fn () => app(UserCleaningOrderService::class)->confirmCompletion($booking))
        ->toThrow(ValidationException::class);
    expect(fn () => app(UserCleaningOrderService::class)->rejectCompletion($booking))
        ->toThrow(ValidationException::class);
    expect(fn () => app(UserCleaningOrderService::class)->requestCompletionExtension($booking, 30))
        ->toThrow(ValidationException::class);

    Sanctum::actingAs($workerUser);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/security-code")
        ->assertStatus(422);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/price-adjustment-requests", [
        'proposed_total_price' => 1300,
    ])->assertUnprocessable();

    $after = DB::table('cleaning_bookings')->where('id', $booking->id)->first();
    expect((array) $after)->toBe((array) $before)
        ->and(DB::table('cleaning_booking_rooms')->where('cleaning_booking_id', $booking->id)->get()->map(fn (object $row): array => (array) $row)->all())
        ->toBe($roomsBefore)
        ->and(DB::table('cleaning_booking_worker_assignments')->where('cleaning_booking_id', $booking->id)->get()->map(fn (object $row): array => (array) $row)->all())
        ->toBe($assignmentsBefore)
        ->and((array) DB::table('booking_security_codes')->where('booking_id', $booking->id)->first())
        ->toBe((array) $securityCodeBefore)
        ->and((array) DB::table('cleaning_time_warnings')->where('id', $warning->id)->first())
        ->toBe((array) $warningBefore)
        ->and((array) DB::table('cleaning_booking_price_adjustment_requests')->where('id', $adjustment->id)->first())
        ->toBe((array) $adjustmentBefore)
        ->and(collect(array_keys($sideEffectTablesBefore))->mapWithKeys(fn (string $table): array => [
            $table => Schema::hasTable($table) ? DB::table($table)->count() : null,
        ])->all())
        ->toBe($sideEffectTablesBefore)
        ->and($after->status)->toBe('partially_completed')
        ->and($after->updated_at)->toBe($before->updated_at)
        ->and(CleaningBookingRoom::query()->where('cleaning_booking_id', $booking->id)->count())->toBe(1)
        ->and(DB::table('booking_security_codes')->where('booking_id', $booking->id)->value('attempts'))->toBe(3)
        ->and(DB::table('booking_security_codes')->where('booking_id', $booking->id)->value('consumed_at'))->toBeNull()
        ->and(CleaningTimeWarning::query()->whereKey($warning->id)->value('worker_response'))->toBeNull()
        ->and(CleaningBookingPriceAdjustmentRequest::query()->whereKey($adjustment->id)->value('status'))
        ->toBe(CleaningPriceAdjustmentRequestStatus::Pending);

    Event::assertNotDispatched(ArrivalVerified::class);
    Event::assertNotDispatched(CleaningBookingTrackingUpdated::class);
    Event::assertNotDispatched(ServiceExtensionRequested::class);
    Notification::assertNothingSent();
});
