<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningBookings\Pages\EditCleaningBooking;
use App\Models\User;
use App\Models\Worker;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $this->actingAs($admin);
});

it('allows admins to edit active and terminal bookings', function (): void {
    $pending = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
    ]);
    $completed = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed,
    ]);
    $cancelled = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Cancelled,
    ]);

    expect(CleaningBookingResource::canEdit($pending))->toBeTrue()
        ->and(CleaningBookingResource::canEdit($completed))->toBeTrue()
        ->and(CleaningBookingResource::canEdit($cancelled))->toBeTrue();

    $this->get(CleaningBookingResource::getUrl('edit', ['record' => $pending], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('بيانات الحجز الأساسية')
        ->assertSee('التقديرات والتسعير')
        ->assertSee('تفاصيل الخدمة والبيانات الديناميكية');

    $this->get(CleaningBookingResource::getUrl('edit', ['record' => $completed], isAbsolute: false))
        ->assertSuccessful();
});

it('updates full cleaning booking information from the dashboard', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
        'number_of_workers' => 1,
        'scheduled_date' => '2026-09-20',
        'scheduled_time' => '09:00:00',
        'total_price' => 100000,
        'property_details' => ['notes' => 'قبل التعديل'],
        'cleaning_services' => [['name' => 'تنظيف عادي']],
    ]);

    Livewire::test(EditCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->fillForm([
            'number_of_workers' => 3,
            'scheduled_date' => '2026-09-21',
            'scheduled_time' => '11:30:00',
            'total_price' => 155000,
            'property_details_json' => '{"notes":"تم التعديل من لوحة التحكم","floor":3}',
            'cleaning_services_json' => '[{"name":"تنظيف عميق","quantity":2}]',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $booking->refresh();

    expect($booking->number_of_workers)->toBe(3)
        ->and($booking->scheduled_date?->format('Y-m-d'))->toBe('2026-09-21')
        ->and((string) $booking->scheduled_time)->toStartWith('11:30')
        ->and($booking->total_price)->toBe(155000)
        ->and(data_get($booking->property_details, 'notes'))->toBe('تم التعديل من لوحة التحكم')
        ->and(data_get($booking->cleaning_services, '0.name'))->toBe('تنظيف عميق');
});

it('shows multiple preferred workers with Arabic labels and integer values', function (): void {
    $firstWorker = Worker::factory()->create(['first_name' => 'أحمد']);
    $secondWorker = Worker::factory()->create(['first_name' => 'سارة']);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
        'preferred_worker_id' => $firstWorker->id,
        'scheduled_time' => '18:00:00',
        'estimated_sqm' => 35.40,
        'estimated_hours' => 2.00,
        'base_price' => 120000.00,
        'addons_total' => 0.00,
        'travel_fee' => 42500.00,
        'admin_margin_amount' => 16500.00,
        'total_price' => 179000.00,
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom-1',
        'room_type' => 'bedroom',
        'room_size' => 'large',
        'display_label' => 'غرفة النوم 1',
        'weight' => 2.00,
        'planned_worker_slot' => 1,
        'planned_preferred_worker_id' => $firstWorker->id,
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bathroom-1',
        'room_type' => 'bathroom',
        'room_size' => 'large',
        'display_label' => 'الحمام 1',
        'weight' => 1.60,
        'planned_worker_slot' => 2,
        'planned_preferred_worker_id' => $secondWorker->id,
    ]);

    $this->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('العاملون المفضلون')
        ->assertSee('أحمد')
        ->assertSee('سارة')
        ->assertSee('06:00 PM')
        ->assertSee('120,000 ل.س')
        ->assertDontSee('120,000.00')
        ->assertDontSee('نمط التعيين')
        ->assertDontSee('تم تثبيت التسعير')
        ->assertDontSee('Accepted worker assignments');
});

it('distinguishes event assistance bookings and hides room information', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
        'property_type' => UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE,
        'property_details' => [
            'event_type' => 'funeral',
            'guest_count' => 80,
            'venue_type' => 'house',
            'custom_service' => 'تجهيز وخدمة المناسبة',
            'hours' => 4,
        ],
        'estimated_sqm' => 0,
        'estimated_hours' => 4,
    ]);

    // Keep a stale room row to verify that event bookings never expose room UI.
    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'living-room-1',
        'room_type' => 'living_room',
        'room_size' => 'medium',
        'display_label' => 'Living Room 1 - Medium',
        'weight' => 1.80,
        'planned_worker_slot' => 1,
    ]);

    $this->get(CleaningBookingResource::getUrl('index', isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('نوع الحجز')
        ->assertSee('مساعدة مناسبة')
        ->assertSee('غير مطبق');

    $this->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('نوع الحجز')
        ->assertSee('مساعدة مناسبة')
        ->assertSee('تفاصيل المناسبة')
        ->assertSee('عزاء')
        ->assertDontSee('نوع العقار')
        ->assertDontSee('المساحة التقديرية')
        ->assertDontSee('تغطية الغرف')
        ->assertDontSee('توزيع الغرف')
        ->assertDontSee('Living Room 1 - Medium');
});
