<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\WorkerCustomerRatingType;
use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Filament\Resources\CleaningBookings\RelationManagers\SessionsRelationManager;
use App\Models\Dispute;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $admin = User::factory()->create(['email' => 'a3-filament-admin@example.com']);
    $admin->assignRole('admin');

    $this->actingAs($admin);
});

it('shows operational coverage and full per-session worker audit details', function (): void {
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::InProgress->value,
        'property_type' => 'event_assistance',
        'number_of_workers' => 2,
    ]);

    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => 'event_assistance',
        'calculation_mode' => 'hour',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 4,
        'required_workers' => 2,
        'coverage_status' => CleaningBookingSessionCoverageStatus::FullyCovered->value,
        'status' => CleaningBookingSessionStatus::InProgress->value,
        'base_price' => 3200,
        'travel_fee' => 300,
        'admin_margin_amount' => 500,
        'total_price' => 4000,
        'is_pricing_final' => true,
        'work_started_at' => now()->subHour(),
    ]);

    $firstWorker = Worker::factory()->create(['first_name' => 'أحمد']);
    $firstWorker->user()->update(['name' => 'أحمد الجلسة']);
    $secondWorker = Worker::factory()->create(['first_name' => 'سارة']);
    $secondWorker->user()->update(['name' => 'سارة الجلسة']);

    CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $firstWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
        'accepted_at' => now()->subHours(3),
        'started_travel_at' => now()->subHours(2),
        'arrived_at' => now()->subHours(1)->subMinutes(30),
        'start_approved_at' => now()->subHours(1)->subMinutes(10),
        'work_started_at' => now()->subHour(),
        'service_share_amount' => 1300,
        'travel_fee' => 200,
        'worker_amount' => 1500,
        'currency' => 'SYP',
    ]);

    CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $secondWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now()->subHours(2),
        'service_share_amount' => 1300,
        'travel_fee' => 100,
        'worker_amount' => 1400,
        'currency' => 'SYP',
    ]);

    WorkerCustomerRating::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $firstWorker->id,
        'customer_id' => $customer->id,
        'rating_type' => WorkerCustomerRatingType::CustomerToWorker->value,
        'rating' => 4,
        'comment' => 'زيارة ممتازة',
    ]);

    Dispute::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'cleaning_booking_session_id' => $session->id,
        'ticket_number' => 'A3-SESSION-1',
        'description' => 'نزاع خاص بهذا اليوم',
        'category' => DisputeCategory::BillingIssue->value,
        'status' => DisputeStatus::Open->value,
        'worker_earnings_frozen' => true,
    ]);

    $viewAction = TestAction::make(ViewAction::class)->table($session);

    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $booking,
        'pageClass' => ViewCleaningBooking::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$session])
        ->assertTableColumnStateSet('coverage_summary', '2 مطلوب · 2 مقبول · 0 متبقٍ', record: $session)
        ->assertTableColumnStateSet('worker_entitlements', 2900.0, record: $session)
        ->assertTableColumnStateSet('review_state', '4.0 / 5 · 1 تقييم', record: $session)
        ->assertTableColumnStateSet('dispute_state', DisputeStatus::Open->label().' · A3-SESSION-1', record: $session)
        ->assertActionVisible($viewAction)
        ->mountAction($viewAction)
        ->assertMountedActionModalSee('ملخص يوم التنفيذ')
        ->assertMountedActionModalSee('أحمد الجلسة')
        ->assertMountedActionModalSee('سارة الجلسة')
        ->assertMountedActionModalSee('1,500')
        ->assertMountedActionModalSee('زيارة ممتازة')
        ->assertMountedActionModalSee('A3-SESSION-1')
        ->assertMountedActionModalSee('نزاع خاص بهذا اليوم');
});
