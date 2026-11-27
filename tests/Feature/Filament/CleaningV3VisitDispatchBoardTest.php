<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningV3VisitDispatches\CleaningV3VisitDispatchResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV3VisitDispatchService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{0:CleaningBooking,1:CleaningBookingSession} */
function v3BoardFixture(bool $v3 = true): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'pending',
        'number_of_workers' => 3,
    ]);
    if ($v3) {
        $booking->forceFill(['contract_version' => 'v3'])->save();
    }

    $start = CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC');
    $appointment = $start->addDay()
        ->setTimezone(config('cleaning_booking_v2.timezone', 'Asia/Damascus'));
    $visit = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $appointment->toDateString(),
        'scheduled_time' => $appointment->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => 3,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);

    if ($v3) {
        app(CleaningV3VisitDispatchService::class)->openSearch($visit, $start);
    }

    return [$booking, $visit];
}

test('Filament V3 shortage board excludes orphaned searches without durable operational admission', function (): void {
    [$openBooking, $openVisit] = v3BoardFixture(true);
    [$resolvedBooking, $resolvedVisit] = v3BoardFixture(true);
    v3BoardFixture(false);

    DB::table('cleaning_v3_visit_dispatches')
        ->where('cleaning_booking_session_id', $resolvedVisit->id)
        ->update(['resolved_at' => now()]);

    $visible = CleaningV3VisitDispatchResource::getEloquentQuery()->get();
    expect($visible)->toHaveCount(0);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);
    expect(CleaningV3VisitDispatchResource::canViewAny())->toBeTrue();

    $url = CleaningV3VisitDispatchResource::getUrl('index', isAbsolute: false);
    $this->get($url)->assertOk()
        ->assertDontSee((string) $openBooking->booking_number)
        ->assertDontSee((string) $resolvedBooking->booking_number);

    $this->assertDatabaseCount('cleaning_v3_dispatch_notice_intents', 0);
});

test('the V3 dispatch admin page is read-only and rejects unrelated users', function (): void {
    [$booking] = v3BoardFixture(true);
    $role = Role::findOrCreate('qa_v3_dispatch_outsider', 'web');
    $outsider = User::factory()->create();
    $outsider->assignRole($role);
    $this->actingAs($outsider);

    expect(CleaningV3VisitDispatchResource::canViewAny())->toBeFalse()
        ->and(CleaningV3VisitDispatchResource::canCreate())->toBeFalse()
        ->and(CleaningV3VisitDispatchResource::canEdit($booking))->toBeFalse()
        ->and(CleaningV3VisitDispatchResource::canDelete($booking))->toBeFalse();

    $response = $this->get(CleaningV3VisitDispatchResource::getUrl('index', isAbsolute: false));
    expect($response->status())->not->toBe(200);
});
