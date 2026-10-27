<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\CleaningConnectedDemoSeeder;
use Database\Seeders\CleaningSupervisorVisitScenarioSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;
use Spatie\Permission\Models\Role;

it('provides all supervisor V2 visit lifecycle fixtures without duplicates', function (): void {
    User::factory()->create(['email' => 'user@dllni.sy', 'is_active' => true]);
    $supervisor = User::factory()->create(['email' => 'supervisor@dllni.sy', 'is_active' => true]);
    $supervisor->assignRole(Role::findOrCreate(SupervisedTeamService::ROLE, 'web'));

    $workers = collect(['cleaning.worker@dllni.sy', 'cleaning.worker2@dllni.sy'])
        ->map(function (string $email): Worker {
            $worker = Worker::factory()->create();
            $worker->user->forceFill(['email' => $email, 'is_active' => true])->save();

            return $worker;
        });

    app(CleaningBillingPolicySeeder::class)->run();
    app(CleaningConnectedDemoSeeder::class)->run();
    app(CleaningSupervisorVisitScenarioSeeder::class)->run();
    app(CleaningSupervisorVisitScenarioSeeder::class)->run();

    $scenarios = [
        'SCHEDULED' => 'scheduled',
        'ASSIGNED' => 'worker_assigned',
        'IN-PROGRESS' => 'in_progress',
        'COMPLETED' => 'completed',
        'CANCELLED' => 'cancelled',
    ];

    $bookings = CleaningBooking::query()
        ->whereIn('booking_number', array_map(
            fn (string $key): string => 'CLN-V2-VISIT-'.$key,
            array_keys($scenarios),
        ))->get();

    expect($bookings)->toHaveCount(5);

    foreach ($scenarios as $key => $status) {
        $booking = $bookings->firstWhere('booking_number', 'CLN-V2-VISIT-'.$key);
        expect($booking)->not->toBeNull()
            ->and($booking->booking_kind)->toBe('standard')
            ->and($booking->sessions()->count())->toBe(1);

        $session = $booking->sessions()->firstOrFail();
        expect($session->status->value)->toBe($status)
            ->and($session->workerAssignments()->whereIn('worker_id', $workers->pluck('id'))->count())->toBe(2)
            ->and(DB::table('cleaning_room_schedules')
                ->where('cleaning_booking_session_id', $session->id)->count())->toBe(1)
            ->and(DB::table('cleaning_booking_session_supervisor_assignments')
                ->where('cleaning_booking_session_id', $session->id)
                ->where('supervisor_user_id', $supervisor->id)
                ->where('active_slot', 1)->count())->toBe(1);
    }

    // The original team flow must remain session-free and unchanged.
    expect(CleaningBooking::query()->where('booking_number', 'CLN-LINKED-001')->firstOrFail()
        ->sessions()->count())->toBe(0);
});
