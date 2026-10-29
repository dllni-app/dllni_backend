<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Models\User;
use App\Models\Worker;
use Filament\Facades\Filament;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningSettledVisitFundingAuditService;
use Spatie\Permission\Models\Role;

it('flags historically settled visits whose worker entitlement exceeds the recorded customer quote without rewriting them', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard', 'number_of_workers' => 1,
        'status' => 'completed', 'total_price' => 150,
    ]);
    $visit = $booking->sessions()->create([
        'sequence' => 1, 'required_workers' => 1, 'duration_hours' => 1,
        'scheduled_date' => now()->toDateString(), 'scheduled_time' => '09:00',
        'status' => 'completed', 'total_price' => 150,
        'payment_status' => 'settled', 'payment_settled_at' => now(),
    ]);
    $visit->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => 'completed', 'accepted_at' => now()->subHours(2),
        'worker_amount' => 160, 'travel_fee' => 10,
        'service_share_amount' => 150, 'admin_margin_amount' => 0,
    ]);

    $audit = app(CleaningSettledVisitFundingAuditService::class)->unresolved($booking);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['gap'])->toBe('10.00')
        ->and($audit[0]['worker_amount'])->toBe('160.00')
        ->and($audit[0]['travel'])->toBe('10.00');

    $this->actingAs($admin)
        ->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('مراجعة تسويات الزيارات')
        ->assertSee('مسجل كمسدّد');

    expect($visit->fresh()->payment_status)->toBe('settled')
        ->and($visit->fresh()->payment_settled_at)->not->toBeNull()
        ->and($visit->fresh()->workerAssignments()->first()->worker_amount)->toBe(160.0);
});

it('does not flag a settled solo visit whose worker obligations fit the recorded quote', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard', 'number_of_workers' => 1,
    ]);
    $visit = $booking->sessions()->create([
        'sequence' => 1, 'required_workers' => 1, 'status' => 'completed',
        'scheduled_date' => now()->toDateString(), 'scheduled_time' => '09:00', 'duration_hours' => 1,
        'total_price' => 200, 'payment_status' => 'settled',
    ]);
    $visit->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => 'completed', 'worker_amount' => 160, 'travel_fee' => 10,
    ]);

    expect(app(CleaningSettledVisitFundingAuditService::class)->unresolved($booking))->toBe([]);
});

it('never treats unconfigured group supervisor compensation as a proven zero obligation', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard', 'number_of_workers' => 2,
    ]);
    $visit = $booking->sessions()->create([
        'sequence' => 1, 'required_workers' => 2, 'status' => 'completed',
        'scheduled_date' => now()->toDateString(), 'scheduled_time' => '09:00', 'duration_hours' => 2,
        'supervision_mode' => 'supervisor', 'supervision_initialized_at' => now(),
        'total_price' => 500, 'payment_status' => 'settled',
    ]);
    foreach ([1, 2] as $_) {
        $visit->workerAssignments()->create([
            'worker_id' => Worker::factory()->create()->id,
            'status' => 'completed', 'worker_amount' => 100, 'travel_fee' => 10,
        ]);
    }
    $audit = app(CleaningSettledVisitFundingAuditService::class)->unresolved($booking);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['reason'])->toContain('supervisor_obligation_invalid');
});
