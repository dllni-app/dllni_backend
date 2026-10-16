<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;

final class AssignSupervisedBookingController
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    public function __invoke(Request $request, int $booking): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active
            && $actor->hasAnyRole(['Super Admin', 'Cleaning Ops Manager']), 403);
        $data = $request->validate([
            'supervisorUserId' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ]);

        $result = DB::transaction(function () use ($actor, $booking, $data): array {
            $order = CleaningBooking::query()->whereKey($booking)->lockForUpdate()->firstOrFail();
            abort_unless($order->isSupervisedTeam(), 404);
            abort_if(in_array($order->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true), 409);
            $supervisor = User::query()->whereKey($data['supervisorUserId'])->lockForUpdate()->firstOrFail();
            abort_unless($supervisor->is_active && $supervisor->hasRole(SupervisedTeamService::ROLE), 422);

            $existing = DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking)
                ->where('active_slot', 1)->whereNull('released_at')->first();
            if ($existing && (int) $existing->supervisor_user_id === (int) $supervisor->id) {
                return ['id' => $existing->id, 'supervisorUserId' => $supervisor->id, 'unchanged' => true];
            }
            if ($existing && ! isset($data['reason'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['reason' => ['Supervisor reassignment requires a recorded reason.']]);
            }
            abort_if($order->supervised_state === 'in_progress', 409, 'إعادة إسناد الطلب الجاري تحتاج مسار استثناء معتمد.');

            // Lock the supervisor principal so concurrent allocations use one serialization point.
            $start = Carbon::parse($order->scheduled_date->format('Y-m-d').' '.$order->scheduled_time, config('app.timezone'));
            $duration = max(1, (int) ($order->supervised_duration_minutes ?: ceil(
                ((float) $order->estimated_hours * 60) / max(1, (int) $order->number_of_workers)
            )));
            $end = $start->copy()->addMinutes($duration);
            $allocated = DB::table('cleaning_supervisor_assignments as sa')
                ->join('cleaning_bookings as b', 'b.id', '=', 'sa.cleaning_booking_id')
                ->where('sa.supervisor_user_id', $supervisor->id)
                ->where('sa.active_slot', 1)->whereNull('sa.released_at')
                ->where('b.id', '<>', $order->id)
                ->whereNotIn('b.status', ['completed', 'cancelled'])
                ->get(['b.scheduled_date', 'b.scheduled_time', 'b.supervised_duration_minutes',
                    'b.estimated_hours', 'b.number_of_workers']);
            foreach ($allocated as $other) {
                $otherStart = Carbon::parse(substr((string) $other->scheduled_date, 0, 10).' '.$other->scheduled_time, config('app.timezone'));
                $otherMinutes = max(1, (int) ($other->supervised_duration_minutes ?: ceil(
                    ((float) $other->estimated_hours * 60) / max(1, (int) $other->number_of_workers)
                )));
                $otherEnd = $otherStart->copy()->addMinutes($otherMinutes);
                abort_if($start->lt($otherEnd) && $otherStart->lt($end), 409, 'المشرف لديه حجز متداخل.');
            }
            if ($existing) {
                DB::table('cleaning_supervisor_assignments')->where('id', $existing->id)->update([
                    'active_slot' => null, 'released_at' => now(),
                    'reason' => $data['reason'], 'updated_at' => now(),
                ]);
            }
            $id = DB::table('cleaning_supervisor_assignments')->insertGetId([
                'cleaning_booking_id' => $booking,
                'supervisor_user_id' => $supervisor->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'active_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->teams->activity($booking, $actor->id, $existing ? 'supervisor_reassigned' : 'supervisor_assigned', [
                'assignmentId' => $id, 'supervisorUserId' => $supervisor->id,
                'previousSupervisorUserId' => $existing?->supervisor_user_id,
            ]);

            return ['id' => $id, 'supervisorUserId' => $supervisor->id, 'unchanged' => false];
        });

        return response()->json(['data' => $result], $result['unchanged'] ? 200 : 201);
    }
}
