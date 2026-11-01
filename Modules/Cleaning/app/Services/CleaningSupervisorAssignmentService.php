<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningEquipmentReservation;

/** The single assignment/transfer boundary shared by the API and the Filament booking page. */
final class CleaningSupervisorAssignmentService
{
    public function __construct(
        private readonly SupervisedTeamService $teams,
        private readonly CleaningSupervisorBookingScheduleService $schedule,
    ) {}

    public function canAssign(User $actor, bool $fromDashboard = false): bool
    {
        // Filament may supply an authenticated User instance loaded with a
        // limited column selection. Re-check the active account in the DB;
        // also prevents stale in-memory auth state from authorizing a change.
        if (! $actor->exists || ! User::query()->whereKey($actor->getKey())->where('is_active', true)->exists()) {
            return false;
        }

        if (! $fromDashboard) {
            // Retain the existing API permission contract.
            return $actor->hasAnyRole(['Super Admin', 'Cleaning Ops Manager']);
        }

        // Filament's admin role can edit bookings; operations managers must
        // also have the normal booking update permission. Support agents with
        // bookings.update alone cannot manage another person's custody.
        return $actor->hasAnyRole(['admin', 'Super Admin'])
            || ($actor->hasRole('Cleaning Ops Manager') && $actor->can('bookings.update'));
    }

    public function isEligible(CleaningBooking $booking): bool
    {
        return $booking->isSupervisedTeam() || $this->schedule->isEligible($booking);
    }

    public function activeSupervisorId(CleaningBooking $booking): ?int
    {
        $id = $booking->supervisorAssignments()->where('active_slot', 1)
            ->whereNull('released_at')->value('supervisor_user_id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{id:int,supervisorUserId:int,unchanged:bool} */
    public function assign(
        User $actor,
        int $bookingId,
        int $supervisorUserId,
        ?string $reason,
        bool $fromDashboard = false,
    ): array {
        abort_unless($this->canAssign($actor, $fromDashboard), 403);

        return DB::transaction(function () use ($actor, $bookingId, $supervisorUserId, $reason): array {
            $order = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            abort_unless($this->isEligible($order), 404);
            abort_if(in_array($order->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true), 409);

            // Serialize assignment attempts on the supervisor principal as well
            // as the booking, so concurrent bookings cannot claim one interval.
            $supervisor = User::query()->whereKey($supervisorUserId)->lockForUpdate()->firstOrFail();
            abort_unless($supervisor->is_active && $supervisor->hasRole(SupervisedTeamService::ROLE), 422);

            $existing = DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $bookingId)
                ->where('active_slot', 1)->whereNull('released_at')->first();
            if ($existing && (int) $existing->supervisor_user_id === (int) $supervisor->id) {
                return ['id' => (int) $existing->id, 'supervisorUserId' => (int) $supervisor->id, 'unchanged' => true];
            }

            $reason = $reason !== null ? mb_trim($reason) : null;
            if ($existing && ($reason === null || mb_strlen($reason) < 10 || mb_strlen($reason) > 500)) {
                throw ValidationException::withMessages([
                    'reason' => ['Supervisor reassignment requires a recorded reason of 10–500 characters.'],
                ]);
            }

            if ($existing) {
                $hasUnreturnedEquipment = CleaningEquipmentReservation::query()
                    ->where('supervisor_user_id', $existing->supervisor_user_id)
                    ->whereNull('return_confirmed_at')
                    ->whereIn('status', [
                        'reserved', 'handed_over', 'acknowledged',
                        'return_pending_confirmation', 'failure_pending_confirmation',
                    ])
                    ->where(function ($query) use ($bookingId): void {
                        $query->where('cleaning_booking_id', $bookingId)
                            ->orWhereHas('bookingSpecialService', fn ($service) => $service->where('cleaning_booking_id', $bookingId));
                    })->exists();
                abort_if($hasUnreturnedEquipment || $order->materialKit()->whereNotNull('received_at')->exists(), 409,
                    'يجب إغلاق عهدة المواد والمعدات قبل تغيير المشرف.');
            }

            abort_if($order->supervised_state === 'in_progress', 409,
                'إعادة إسناد الطلب الجاري تحتاج مسار استثناء معتمد.');

            $this->schedule->assertNoOverlap($order, $supervisor);

            if ($existing) {
                DB::table('cleaning_supervisor_assignments')->where('id', $existing->id)->update([
                    'active_slot' => null, 'released_at' => now(),
                    'reason' => $reason, 'updated_at' => now(),
                ]);
            }

            $id = DB::table('cleaning_supervisor_assignments')->insertGetId([
                'cleaning_booking_id' => $bookingId,
                'supervisor_user_id' => $supervisor->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'active_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->teams->activity($bookingId, $actor->id, $existing ? 'supervisor_reassigned' : 'supervisor_assigned', [
                'assignmentId' => $id,
                'supervisorUserId' => $supervisor->id,
                'previousSupervisorUserId' => $existing?->supervisor_user_id,
            ]);

            return ['id' => $id, 'supervisorUserId' => (int) $supervisor->id, 'unchanged' => false];
        }, 3);
    }

    private function assertAssignableSupervisor(User $supervisor): void
    {
        abort_unless($supervisor->is_active && $supervisor->hasRole(SupervisedTeamService::ROLE), 422);
    }

    private function normalizedReason(?string $reason): ?string
    {
        $trimmed = $reason !== null ? mb_trim($reason) : null;

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertReassignmentReason(?string $reason): void
    {
        if ($reason === null || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => ['Supervisor reassignment requires a recorded reason of 10–500 characters.'],
            ]);
        }
    }

    private function assertNoBookingCustody(CleaningBooking $booking, int $supervisorUserId): void
    {
        $hasUnreturnedEquipment = CleaningEquipmentReservation::query()
            ->where('supervisor_user_id', $supervisorUserId)
            ->whereNull('return_confirmed_at')
            ->whereIn('status', [
                'reserved', 'handed_over', 'acknowledged',
                'return_pending_confirmation', 'failure_pending_confirmation',
            ])
            ->where(function ($query) use ($booking): void {
                $query->where('cleaning_booking_id', $booking->id)
                    ->orWhereHas('bookingSpecialService', fn ($service) => $service->where('cleaning_booking_id', $booking->id));
            })->exists();

        abort_if($hasUnreturnedEquipment || $booking->materialKit()->whereNotNull('received_at')->exists(), 409,
            'يجب إغلاق عهدة المواد والمعدات قبل تغيير المشرف.');
    }

    /**
     * @param  list<array{0:CarbonImmutable,1:CarbonImmutable}>  $target
     * @param  list<array{0:CarbonImmutable,1:CarbonImmutable}>  $existing
     */
    private function assertIntervalsDoNotOverlap(array $target, array $existing): void
    {
        foreach ($existing as [$otherStart, $otherEnd]) {
            foreach ($target as [$start, $end]) {
                abort_if($start->lt($otherEnd) && $otherStart->lt($end), 409, 'المشرف لديه حجز متداخل.');
            }
        }
    }
}
