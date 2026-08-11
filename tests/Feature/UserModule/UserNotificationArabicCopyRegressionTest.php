<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('repairs the legacy mojibake preferred worker rejection copy in the notification feed', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'Illuminate\\Notifications\\DatabaseNotification',
        'data' => [
            'type' => 'preferred_worker_rejection_decision_required',
            'canonical_type' => 'cleaning.booking.preferred_worker_rejected_decision_required',
            'title' => 'Ø±ÙØ¶ Ø§Ù„Ø¹Ø§Ù…Ù„ Ø§Ù„Ù…Ø®ØµØµ Ø§Ù„Ø·Ù„Ø¨',
            'body' => 'Ø±ÙØ¶ Ø§Ù„Ø¹Ø§Ù…Ù„ Ø§Ù„Ù…Ø®ØµØµ Ø§Ù„Ø·Ù„Ø¨.',
        ],
        'read_at' => null,
    ]);

    $response = $this->getJson('/api/v1/user/notifications');

    $response->assertOk();
    expect($response->json('data.0.title'))->toBe('رفض العامل المخصص الطلب');
    expect($response->json('data.0.body'))->toBe('رفض العامل المخصص الطلب. افتح التطبيق لتحويله إلى طلب عام أو إلغائه بدون رسوم.');
});

it('renders the cleaning booking status in Arabic in notification body while keeping the raw status in data', function (): void {
    $user = User::factory()->create();

    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'gender_preference' => 'any',
    ]));

    $notification = new BookingLifecycleNotification(
        booking: $booking,
        canonicalType: 'cleaning.booking.updated',
        actorRole: 'system',
        targetRole: 'customer',
    );

    $payload = $notification->toArray($user);
    $arabicStatus = Lang::get('cleaning_admin.enums.cleaning_booking_status.worker_assigned', [], 'ar');

    expect($arabicStatus)->not->toBe('cleaning_admin.enums.cleaning_booking_status.worker_assigned');
    expect($payload['body'])->toContain($arabicStatus);
    expect($payload['body'])->not->toContain(CleaningBookingStatus::WorkerAssigned->value);
    expect(data_get($payload, 'data.status'))->toBe(CleaningBookingStatus::WorkerAssigned->value);
});
