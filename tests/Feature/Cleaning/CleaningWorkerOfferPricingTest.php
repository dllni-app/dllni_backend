<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;

it('returns the current worker complete pricing before accepting the cleaning order', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 1000,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'number_of_workers' => 2,
        'base_price' => 10000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 10000,
        'is_pricing_final' => false,
        'estimated_hours' => 4,
        'total_hours' => 4,
        'address_latitude' => 33.6,
        'address_longitude' => 36.3,
    ]);

    Sanctum::actingAs($workerUser);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk()
        ->assertJsonPath('data.basePrice', 5000)
        ->assertJsonPath('data.travelFee', 11120)
        ->assertJsonPath('data.adminMargin', 500)
        ->assertJsonPath('data.workerAmount', 15620)
        ->assertJsonPath('data.totalPrice', 15620)
        ->assertJsonPath('data.isPricingFinal', false)
        ->assertJsonPath('data.totalHours', 2)
        ->assertJsonPath('data.bookingTotalHours', 4)
        ->assertJsonPath('data.bookingBasePrice', 10000)
        ->assertJsonPath('data.bookingAdminMargin', 1000)
        ->assertJsonPath('data.bookingTotalPrice', 11000)
        ->assertJsonPath('data.myAssignment.workerId', $worker->id)
        ->assertJsonPath('data.myAssignment.serviceShareAmount', 5000)
        ->assertJsonPath('data.myAssignment.travelFee', 11120)
        ->assertJsonPath('data.myAssignment.adminMarginAmount', 500)
        ->assertJsonPath('data.myAssignment.workerAmount', 15620)
        ->assertJsonPath('data.myAssignment.totalPrice', 15620)
        ->assertJsonPath('data.myAssignment.grossTotalPrice', 16120)
        ->assertJsonPath('data.myAssignment.totalHours', 2)
        ->assertJsonPath('data.myAssignment.isPreview', true)
        ->assertJsonPath('data.myAssignment.isPricingFinal', false);
});

it('calculates the cleaning worker offer time and service share from the planned room weight', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 1000,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $workerUser = User::factory()->create();
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 2,
        'base_price' => 400,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 400,
        'is_pricing_final' => false,
        'estimated_hours' => 8,
        'total_hours' => 8,
        'address_latitude' => 33.6,
        'address_longitude' => 36.3,
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom.small.1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Bedroom 1 - Small',
        'weight' => 1,
        'planned_worker_slot' => 1,
        'planned_preferred_worker_id' => null,
        'assigned_worker_id' => null,
        'assignment_source' => null,
    ]);
    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'living_room.large.1',
        'room_type' => 'living_room',
        'room_size' => 'large',
        'display_label' => 'Living Room 1 - Large',
        'weight' => 3,
        'planned_worker_slot' => 2,
        'planned_preferred_worker_id' => null,
        'assigned_worker_id' => null,
        'assignment_source' => null,
    ]);

    Sanctum::actingAs($workerUser);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk();
    expect((float) $response->json('data.totalHours'))->toBe(2.0);
    expect((float) $response->json('data.basePrice'))->toBe(100.0);
    expect((float) $response->json('data.workerOffer.totalHours'))->toBe(2.0);
    expect((float) $response->json('data.workerOffer.serviceShareAmount'))->toBe(100.0);
});

it('keeps every event worker on the full event duration and preserves the worker share after acceptance', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 0,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'property_type' => 'event_assistance',
        'property_details' => ['hours' => 6],
        'assignment_mode' => 'open_count',
        'number_of_workers' => 3,
        'base_price' => 4320,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 1080,
        'total_price' => 5400,
        'is_pricing_final' => false,
        'estimated_hours' => 6,
        'total_hours' => 6,
        'address_latitude' => 33.6,
        'address_longitude' => 36.3,
        'gender_preference' => 'any',
    ]);

    Sanctum::actingAs($workerUser);

    $beforeAccept = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");
    $beforeAccept->assertOk();
    expect((float) $beforeAccept->json('data.totalHours'))->toBe(6.0);
    expect((float) $beforeAccept->json('data.workerOffer.totalHours'))->toBe(6.0);
    expect((float) $beforeAccept->json('data.workerOffer.serviceShareAmount'))->toBe(1440.0);

    $afterAccept = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/accept");
    $afterAccept->assertOk();

    expect((float) $afterAccept->json('data.totalHours'))->toBe(6.0);
    expect((float) $afterAccept->json('data.workerOffer.totalHours'))->toBe(6.0);
    expect((float) $afterAccept->json('data.workerOffer.serviceShareAmount'))->toBe(1440.0);
    expect((float) $afterAccept->json('data.myAssignment.totalHours'))->toBe(6.0);
    expect((float) $afterAccept->json('data.myAssignment.serviceShareAmount'))->toBe(1440.0);

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'service_share_amount' => 1440,
    ]);
});

it('uses the discounted admin margin in pending worker api offers without reducing service share', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 0,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $coupon = PlatformCoupon::query()->create([
        'code' => 'WORKER10',
        'title_ar' => 'خصم تنظيف',
        'title_en' => 'Cleaning discount',
        'description_ar' => 'اختبار',
        'description_en' => 'Test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
    ]);

    $workerUser = User::factory()->create();
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'number_of_workers' => 1,
        'base_price' => 960,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 144,
        'subtotal_before_discount' => 1200,
        'discount_amount' => 96,
        'total_price' => 1104,
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
        'is_pricing_final' => false,
        'estimated_hours' => 2,
        'total_hours' => 2,
        'address_latitude' => 33.6,
        'address_longitude' => 36.3,
    ]));

    Sanctum::actingAs($workerUser);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk()
        ->assertJsonPath('data.basePrice', 960)
        ->assertJsonPath('data.serviceShareAmount', 960)
        ->assertJsonPath('data.adminMargin', 144)
        ->assertJsonPath('data.workerOffer.serviceShareAmount', 960)
        ->assertJsonPath('data.workerOffer.adminMarginAmount', 144)
        ->assertJsonPath('data.myAssignment.serviceShareAmount', 960)
        ->assertJsonPath('data.myAssignment.adminMarginAmount', 144)
        ->assertJsonPath('data.couponApplied', true)
        ->assertJsonPath('data.couponCode', 'WORKER10')
        ->assertJsonPath('data.discountAmount', 96)
        ->assertJsonPath('data.subtotalBeforeDiscount', 1200);
});

it('reduces pending worker service share only by coupon excess above admin margin', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 0,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $coupon = PlatformCoupon::query()->create([
        'code' => 'WORKER15EXCESS',
        'title_ar' => 'خصم تنظيف',
        'title_en' => 'Cleaning discount',
        'description_ar' => 'اختبار',
        'description_en' => 'Test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 15,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
    ]);

    $workerUser = User::factory()->create();
    Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'number_of_workers' => 1,
        'base_price' => 1000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'subtotal_before_discount' => 1100,
        'discount_amount' => 150,
        'total_price' => 950,
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
        'is_pricing_final' => false,
        'estimated_hours' => 2,
        'total_hours' => 2,
        'address_latitude' => 33.6,
        'address_longitude' => 36.3,
    ]));

    Sanctum::actingAs($workerUser);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk()
        ->assertJsonPath('data.basePrice', 950)
        ->assertJsonPath('data.serviceShareAmount', 950)
        ->assertJsonPath('data.adminMargin', 0)
        ->assertJsonPath('data.workerOffer.serviceShareAmount', 950)
        ->assertJsonPath('data.workerOffer.adminMarginAmount', 0);
});
