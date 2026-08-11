<?php

declare(strict_types=1);

use App\Filament\Resources\EventBookings\EventBookingResource;
use App\Filament\Resources\EventBookings\Pages\ViewEventBooking;
use App\Models\CancellationPolicy;
use App\Models\User;
use Livewire\Livewire;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;
use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\EventBooking;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'event-booking-admin@example.com',
    ]);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
    $this->withSession(['cleaning_admin_locale' => 'en']);
    app()->setLocale('en');
});

it('renders event booking details on the Filament view page', function (): void {
    $customer = User::factory()->create(['name' => 'Event QA Customer']);
    $cancellationPolicy = CancellationPolicy::create([
        'module' => 'cleaning',
        'name' => 'Cancellation QA Policy',
        'description' => 'Cancellation policy for the Filament detail test.',
        'rules' => [],
        'is_active' => true,
        'is_default' => false,
    ]);
    $billingPolicy = CleaningBillingPolicy::create([
        'name' => 'Billing QA Policy',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => false,
    ]);
    $service = CleaningService::create([
        'name' => 'Event QA Service',
        'slug' => 'event-qa-service',
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'Service for the Filament detail test.',
        'is_active' => true,
    ]);
    $booking = EventBooking::factory()->create([
        'customer_id' => $customer->id,
        'cancellation_policy_id' => $cancellationPolicy->id,
        'billing_policy_id' => $billingPolicy->id,
        'booking_number' => 'EVT-QA-DETAIL-001',
        'status' => EventBookingStatus::Confirmed->value,
        'event_type' => EventType::Birthday->value,
        'guest_count_min' => 10,
        'guest_count_max' => 25,
        'gender_preference' => 'any',
        'suggested_team_size' => 3,
        'scheduled_date' => '2026-08-01',
        'scheduled_time' => '18:30',
        'total_hours' => 6,
        'base_price' => 200,
        'travel_fee' => 25,
        'total_price' => 225,
        'terms_accepted' => true,
    ]);
    $booking->services()->attach($service->id, [
        'quantity' => 2,
        'unit_price' => 15,
        'total_price' => 30,
    ]);

    $this->get(EventBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee($booking->booking_number);

    Livewire::test(ViewEventBooking::class, ['record' => $booking->getRouteKey()])
        ->assertSee($booking->booking_number)
        ->assertSee($customer->name)
        ->assertSee(EventBookingStatus::Confirmed->label())
        ->assertSee(EventType::Birthday->label())
        ->assertSee((string) $booking->guest_count_min)
        ->assertSee((string) $booking->guest_count_max)
        ->assertSee((string) $booking->suggested_team_size)
        ->assertSee($booking->scheduled_time)
        ->assertSee((string) $booking->total_hours)
        ->assertSee(__('cleaning_admin.boolean.yes'))
        ->assertSee('SYP')
        ->assertSee((string) (int) $booking->base_price)
        ->assertSee((string) (int) $booking->travel_fee)
        ->assertSee((string) (int) $booking->total_price)
        ->assertSee($cancellationPolicy->name)
        ->assertSee($billingPolicy->name)
        ->assertSee($service->name)
        ->assertSee('30');
});
