<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Notification Availability Test Cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ]
    );

    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Notification Availability Test Billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ]
    );

    $this->bookingPayload = static function (int $numberOfWorkers): array {
        return [
            'propertyType' => 'apartment',
            'propertyDetails' => [
                'address' => 'Damascus - Mazzeh',
                'location_name' => 'Notification test home',
                'rooms' => 2,
                'bedrooms' => 1,
                'bathrooms' => 1,
                'kitchens' => 0,
                'living_room_size' => 'small',
                'room_size_breakdown' => [
                    'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                    'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                    'kitchen' => ['small' => 0, 'medium' => 0, 'large' => 0],
                    'living_room' => ['small' => 0, 'medium' => 0, 'large' => 0],
                    'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
                ],
            ],
            'assignmentMode' => 'open_count',
            'numberOfWorkers' => $numberOfWorkers,
            'scheduledDate' => now()->addDay()->format('Y-m-d'),
            'scheduledTime' => '09:00',
            'addressLatitude' => 33.5138,
            'addressLongitude' => 36.2765,
            'genderPreference' => 'any',
            'termsAccepted' => true,
        ];
    };

    $this->createWorker = static function (string $email, float $latitude): array {
        $user = User::factory()->create(['email' => $email]);
        $worker = Worker::factory()->create([
            'user_id' => $user->id,
            'home_address' => 'Worker home',
            'home_latitude' => $latitude,
            'home_longitude' => 36.3,
        ]);

        return [$user, $worker];
    };

    $this->createNewOrderNotification = static function (User $user, CleaningBooking $booking): DatabaseNotification {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => NewOrderRequestNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'type' => 'new_order',
                'canonical_type' => 'cleaning.booking.new_order_request',
                'canonicalType' => 'cleaning.booking.new_order_request',
                'module' => 'cleaning',
                'category' => 'orders',
                'priority' => 'high',
                'title' => 'طلب جديد',
                'body' => 'طلب تنظيف جديد',
                'message' => 'طلب تنظيف جديد',
                'bookingId' => (int) $booking->id,
                'orderId' => (int) $booking->id,
                'data' => [
                    'bookingId' => (int) $booking->id,
                    'orderId' => (int) $booking->id,
                    'action' => 'new_order_request',
                ],
            ],
            'read_at' => null,
        ]);
    };
});

it('deletes every new-order notification when a single-worker booking is accepted', function (): void {
    $customer = User::factory()->create(['email' => 'single-notification-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->bookingPayload)(1));
    $create->assertCreated();

    $booking = CleaningBooking::query()->findOrFail((int) $create->json('order.id'));

    [$acceptedUser] = ($this->createWorker)('single-notification-accepted@example.com', 33.51);
    [$otherUser] = ($this->createWorker)('single-notification-other@example.com', 33.52);

    $acceptedNotification = ($this->createNewOrderNotification)($acceptedUser, $booking);
    $otherNotification = ($this->createNewOrderNotification)($otherUser, $booking);

    Sanctum::actingAs($acceptedUser);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/accept")->assertOk();

    expect(DatabaseNotification::query()->whereKey($acceptedNotification->id)->exists())->toBeFalse();
    expect(DatabaseNotification::query()->whereKey($otherNotification->id)->exists())->toBeFalse();

    Sanctum::actingAs($otherUser);
    getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertStatus(409);

    Sanctum::actingAs($acceptedUser);
    getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
});

it('keeps notifications available until all required workers accept a multi-worker booking', function (): void {
    $customer = User::factory()->create(['email' => 'multi-notification-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->bookingPayload)(2));
    $create->assertCreated();

    $booking = CleaningBooking::query()->findOrFail((int) $create->json('order.id'));

    [$workerOneUser] = ($this->createWorker)('multi-notification-worker-1@example.com', 33.51);
    [$workerTwoUser] = ($this->createWorker)('multi-notification-worker-2@example.com', 33.52);
    [$otherUser] = ($this->createWorker)('multi-notification-other@example.com', 33.53);

    $workerOneNotification = ($this->createNewOrderNotification)($workerOneUser, $booking);
    $workerTwoNotification = ($this->createNewOrderNotification)($workerTwoUser, $booking);
    $otherNotification = ($this->createNewOrderNotification)($otherUser, $booking);

    Sanctum::actingAs($workerOneUser);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect(DatabaseNotification::query()->whereKey($workerOneNotification->id)->exists())->toBeTrue();
    expect(DatabaseNotification::query()->whereKey($workerTwoNotification->id)->exists())->toBeTrue();
    expect(DatabaseNotification::query()->whereKey($otherNotification->id)->exists())->toBeTrue();

    Sanctum::actingAs($otherUser);
    getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();

    Sanctum::actingAs($workerTwoUser);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'worker_assigned');

    expect(DatabaseNotification::query()->whereKey($workerOneNotification->id)->exists())->toBeFalse();
    expect(DatabaseNotification::query()->whereKey($workerTwoNotification->id)->exists())->toBeFalse();
    expect(DatabaseNotification::query()->whereKey($otherNotification->id)->exists())->toBeFalse();

    Sanctum::actingAs($otherUser);
    getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertStatus(409);

    Sanctum::actingAs($workerOneUser);
    getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();

    Sanctum::actingAs($workerTwoUser);
    getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
});

it('does not return legacy unavailable new-order notifications in the notification feed', function (): void {
    $customer = User::factory()->create(['email' => 'legacy-notification-customer@example.com']);
    Sanctum::actingAs($customer);

    $create = postJson('/api/v1/user/cleaning/orders', ($this->bookingPayload)(1));
    $create->assertCreated();

    $booking = CleaningBooking::query()->findOrFail((int) $create->json('order.id'));
    [$workerUser] = ($this->createWorker)('legacy-notification-worker@example.com', 33.51);

    $notification = ($this->createNewOrderNotification)($workerUser, $booking);
    $payload = $notification->data;
    $payload['state'] = 'unavailable';
    $payload['actionable'] = false;
    $payload['data']['state'] = 'unavailable';
    $payload['data']['actionable'] = false;

    $notification->forceFill([
        'data' => $payload,
        'read_at' => null,
    ])->save();

    Sanctum::actingAs($workerUser);
    getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonMissing(['id' => $notification->id])
        ->assertJsonPath('countUnread', 0);
});
