<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningBookings\Pages\EditCleaningBooking;
use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Models\User;
use App\Models\Worker;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerLocationPoint;
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
        ->assertSee('العامل المحدد مسبقاً')
        ->assertDontSee('التقديرات والتسعير')
        ->assertDontSee('تفاصيل الخدمة')
        ->assertDontSee('وافق العميل على الشروط')
        ->assertDontSee('سبب الإلغاء')
        ->assertDontSee('أوقات التنفيذ');

    $this->get(CleaningBookingResource::getUrl('edit', ['record' => $completed], isAbsolute: false))
        ->assertSuccessful();
});

it('shows customer phone mission time and coupon application details', function (): void {
    $customer = User::factory()->create([
        'name' => 'عميل الاختبار',
        'phone' => '0991234567',
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_time' => '14:30:00',
        'platform_coupon_code' => 'SAVE10',
        'discount_amount' => 120,
        'subtotal_before_discount' => 1200,
        'total_price' => 1080,
    ]);

    $this->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('رقم العميل')
        ->assertSee('0991234567')
        ->assertSee('وقت المهمة')
        ->assertSee('02:30 PM')
        ->assertSee('تم تطبيق كوبون؟')
        ->assertSee('نعم')
        ->assertSee('قيمة خصم الكوبون')
        ->assertSee('120 ل.س');
});

it('allows an admin to cancel a booking from its dashboard details', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
        'cancellation_fee' => 75,
    ]);

    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionExists('cancel_booking')
        ->callAction('cancel_booking', data: [
            'reason' => 'إلغاء إداري للاختبار',
        ])
        ->assertHasNoActionErrors();

    $booking->refresh();

    expect($booking->status)->toBe(CleaningBookingStatus::Cancelled)
        ->and($booking->cancelled_by_role)->toBe('admin')
        ->and($booking->cancellation_reason)->toBe('إلغاء إداري للاختبار')
        ->and((float) $booking->cancellation_fee)->toBe(0.0)
        ->and($booking->cancelled_at)->not->toBeNull();
});

it('updates operational booking fields and selected rooms without changing pricing or calculated details', function (): void {
    $propertyDetails = [
        'rooms' => 2,
        'room_size_breakdown' => [
            'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            'kitchen' => ['small' => 0, 'medium' => 0, 'large' => 0],
            'living_room' => ['small' => 0, 'medium' => 0, 'large' => 0],
            'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            'corridor' => ['small' => 0, 'medium' => 0, 'large' => 0],
            'shed' => ['small' => 0, 'medium' => 0, 'large' => 0],
        ],
    ];

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
        'number_of_workers' => 1,
        'scheduled_date' => '2026-09-20',
        'scheduled_time' => '09:00:00',
        'estimated_sqm' => 181,
        'total_price' => 100000,
        'property_details' => $propertyDetails,
        'cleaning_services' => [['name' => 'تنظيف عادي']],
    ]);

    foreach ([
        ['room_key' => 'bedroom.small.1', 'room_type' => 'bedroom', 'room_size' => 'small', 'display_label' => 'Bedroom 1 - Small', 'weight' => 1.0],
        ['room_key' => 'bathroom.small.1', 'room_type' => 'bathroom', 'room_size' => 'small', 'display_label' => 'Bathroom 1 - Small', 'weight' => 0.8],
    ] as $room) {
        CleaningBookingRoom::query()->create(['cleaning_booking_id' => $booking->id, ...$room]);
    }

    Livewire::test(EditCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->fillForm([
            'number_of_workers' => 3,
            'scheduled_date' => '2026-09-21',
            'scheduled_time' => '11:30:00',
            'selected_room_keys' => ['bedroom.small.1'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $booking->refresh();

    expect($booking->number_of_workers)->toBe(3)
        ->and($booking->scheduled_date?->format('Y-m-d'))->toBe('2026-09-21')
        ->and((string) $booking->scheduled_time)->toStartWith('11:30')
        ->and((float) $booking->total_price)->toBe(100000.0)
        ->and((float) $booking->estimated_sqm)->toBe(181.0)
        ->and(data_get($booking->property_details, 'room_size_breakdown'))->toBe($propertyDetails['room_size_breakdown'])
        ->and(data_get($booking->property_details, 'rooms'))->toBe(2)
        ->and(data_get($booking->cleaning_services, '0.name'))->toBe('تنظيف عادي')
        ->and($booking->rooms()->pluck('room_key')->all())->toBe(['bedroom.small.1']);
});

it('shows multiple preferred workers with Arabic labels and integer values', function (): void {
    $firstWorker = Worker::factory()->financiallyEligible()->create(['first_name' => 'أحمد']);
    $secondWorker = Worker::factory()->financiallyEligible()->create(['first_name' => 'سارة']);

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
        ->assertDontSee('نوع المكان')
        ->assertDontSee('نوع العقار')
        ->assertDontSee('المساحة التقديرية')
        ->assertDontSee('تغطية الغرف')
        ->assertDontSee('توزيع الغرف')
        ->assertDontSee('Living Room 1 - Medium');
});

it('shows Arabic room names final worker wages and the recorded worker route', function (): void {
    $workerUser = User::factory()->create(['name' => 'عامل المسار']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'first_name' => 'عامل المسار',
    ]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::Completed,
        'address_latitude' => 36.2020000,
        'address_longitude' => 37.1340000,
    ]);

    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Completed,
        'accepted_at' => now()->subHours(2),
        'started_travel_at' => now()->subHour(),
        'arrived_at' => now()->subMinutes(30),
        'last_latitude' => 36.2015000,
        'last_longitude' => 37.1335000,
        'location_updated_at' => now()->subMinutes(30),
        'service_share_amount' => 900,
        'travel_fee' => 60,
        'admin_margin_amount' => 240,
        'worker_amount' => 960,
        'currency' => 'SYP',
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'balcony.medium.1',
        'room_type' => 'balcony',
        'room_size' => 'medium',
        'display_label' => 'Balcony 1 - Medium',
        'weight' => 1,
        'assigned_worker_id' => $worker->id,
    ]);

    foreach ([
        [36.1980000, 37.1290000, now()->subMinutes(55)],
        [36.2015000, 37.1335000, now()->subMinutes(30)],
    ] as [$latitude, $longitude, $recordedAt]) {
        CleaningBookingWorkerLocationPoint::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_worker_assignment_id' => $assignment->id,
            'worker_id' => $worker->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'recorded_at' => $recordedAt,
        ]);
    }

    $this->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('شرفة متوسطة')
        ->assertSee('أجور العاملين النهائية')
        ->assertSee('عامل المسار')
        ->assertSee('960 ل.س')
        ->assertSee('الخريطة ومسار العاملين')
        ->assertSee('2 نقطة مسجلة')
        ->assertSee('data-cleaning-route-map', false);
});

it('keeps legacy worker tracking readable when booking snapshot columns are not selected', function (): void {
    $workerUser = User::factory()->create(['name' => 'عامل قديم']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'first_name' => 'عامل قديم',
    ]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'started_travel_at' => now()->subHour(),
        'arrived_at' => null,
        'address_latitude' => 36.2020000,
        'address_longitude' => 37.1340000,
    ]);

    CleaningBookingWorkerLocationPoint::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_worker_assignment_id' => null,
        'worker_id' => $worker->id,
        'latitude' => 36.2015000,
        'longitude' => 37.1335000,
        'recorded_at' => now()->subMinutes(5),
    ]);

    $partialRecord = CleaningBooking::query()
        ->select([
            'id',
            'worker_id',
            'number_of_workers',
            'status',
            'started_travel_at',
            'arrived_at',
            'address_latitude',
            'address_longitude',
        ])
        ->findOrFail($booking->id);

    $method = new ReflectionMethod(CleaningBookingResource::class, 'workerTrackingState');
    $state = $method->invoke(null, $partialRecord);

    expect($state['workers'])->toHaveCount(1)
        ->and($state['workers'][0]['latitude'])->toBe(36.2015)
        ->and($state['workers'][0]['longitude'])->toBe(37.1335)
        ->and($state['workers'][0]['routePointsCount'])->toBe(1);
});
