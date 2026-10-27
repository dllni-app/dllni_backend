<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\CleaningConnectedDemoSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;
use Spatie\Permission\Models\Role;

it('seeds canonical linked bookings as session-free booking-level team workflows', function (): void {
    $customer = User::factory()->create(['email' => 'user@dllni.sy', 'is_active' => true]);
    $supervisor = User::factory()->create(['email' => 'supervisor@dllni.sy', 'is_active' => true]);
    $supervisor->assignRole(Role::findOrCreate(SupervisedTeamService::ROLE, 'web'));
    $workers = collect([
        ['cleaning.worker@dllni.sy', '+963944100001'],
        ['cleaning.worker2@dllni.sy', '+963944100004'],
    ])->map(function (array $identity): Worker {
        $worker = Worker::factory()->create();
        $worker->user->forceFill(['email' => $identity[0], 'phone' => $identity[1], 'is_active' => true])->save();

        return $worker;
    });

    app(CleaningBillingPolicySeeder::class)->run();
    app(CleaningConnectedDemoSeeder::class)->run();

    $linked = CleaningBooking::query()->whereIn('booking_number', [
        'CLN-LINKED-001', 'CLN-LINKED-002',
    ])->orderBy('booking_number')->get();
    expect($linked)->toHaveCount(2);

    foreach ($linked as $booking) {
        expect((int) $booking->customer_id)->toBe((int) $customer->id)
            ->and($booking->booking_kind)->toBe(SupervisedTeamService::KIND)
            ->and($booking->workerAssignments()->whereIn('worker_id', $workers->pluck('id'))->count())->toBe(2)
            ->and($booking->sessions()->exists())->toBeFalse()
            ->and($booking->supervisorAssignments()->where('supervisor_user_id', $supervisor->id)
                ->whereNull('released_at')->exists())->toBeTrue()
            ->and($booking->rooms()->whereNotNull('assigned_worker_id')->exists())->toBeFalse();
    }

    $scheduled = $linked->firstWhere('booking_number', 'CLN-LINKED-001');
    $start = \Carbon\CarbonImmutable::parse($scheduled->scheduled_date->format('Y-m-d').' '.$scheduled->scheduled_time, config('app.timezone'));
    expect($scheduled->status->value)->toBe('worker_assigned')
        ->and($scheduled->supervised_duration_minutes)->toBe(120)
        ->and($scheduled->supervised_scheduled_end_at->equalTo($start->addMinutes(120)))->toBeTrue()
        ->and($scheduled->supervised_original_end_at->equalTo($scheduled->supervised_scheduled_end_at))->toBeTrue();

    $inProgress = $linked->firstWhere('booking_number', 'CLN-LINKED-002');
    expect($inProgress->status->value)->toBe('in_progress')
        ->and($inProgress->workerAssignments()->where(fn ($query) => $query
            ->whereNotNull('started_travel_at')->orWhereNotNull('arrived_at'))->exists())->toBeFalse();

    expect(DB::table('cleaning_booking_sessions')->where('cleaning_booking_id',
        CleaningBooking::query()->where('booking_number', 'CLN-SESSION-GUARD-001')->value('id'))->count())->toBe(1);
});
