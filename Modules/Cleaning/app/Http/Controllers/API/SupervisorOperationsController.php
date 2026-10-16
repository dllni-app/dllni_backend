<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;

final class SupervisorOperationsController
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    private function authorizeOperations(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active
            && $actor->hasAnyRole(['admin', 'Super Admin', 'Cleaning Ops Manager']), 403);
        return $actor;
    }

    public function assign(Request $request, CleaningBooking $booking): JsonResponse
    {
        $actor = $this->authorizeOperations($request);
        $data = $request->validate(['supervisorUserId' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500']]);
        $supervisor = User::query()->findOrFail($data['supervisorUserId']);
        abort_unless($supervisor->is_active && $supervisor->hasRole(SupervisedTeamService::ROLE), 422);
        $result = DB::transaction(function () use ($actor, $booking, $supervisor, $data): array {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->booking_kind === SupervisedTeamService::KIND, 422);
            abort_unless(! in_array($this->teams->state($locked), ['completed'], true), 409);
            $old = DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $locked->id)
                ->where('active_slot', 1)->lockForUpdate()->first();
            if ($old && (int) $old->supervisor_user_id === (int) $supervisor->id) {
                return ['supervisorUserId' => $supervisor->id, 'unchanged' => true];
            }
            $start = Carbon::parse($locked->scheduled_date->format('Y-m-d').' '.$locked->scheduled_time);
            $end = $locked->supervised_scheduled_end_at;
            abort_unless($end, 409);
            $conflicts = DB::table('cleaning_supervisor_assignments as sa')
                ->join('cleaning_bookings as b', 'b.id', '=', 'sa.cleaning_booking_id')
                ->where('sa.supervisor_user_id', $supervisor->id)->where('sa.active_slot', 1)
                ->where('b.id', '<>', $locked->id)
                ->whereDate('b.scheduled_date', '<=', $end->toDateString())
                ->where('b.supervised_scheduled_end_at', '>', $start)
                ->whereNotIn('b.supervised_state', ['completed', 'cancelled'])->exists();
            abort_if($conflicts, 409, 'المشرف لديه موعد آخر متداخل.');
            if ($old) {
                DB::table('cleaning_supervisor_assignments')->where('id', $old->id)
                    ->update(['active_slot' => null, 'released_at' => now(),
                        'reason' => $data['reason'] ?? 'إعادة إسناد', 'updated_at' => now()]);
            }
            DB::table('cleaning_supervisor_assignments')->insert([
                'cleaning_booking_id' => $locked->id, 'supervisor_user_id' => $supervisor->id,
                'assigned_by_user_id' => $actor->id, 'assigned_at' => now(), 'active_slot' => 1,
                'reason' => $data['reason'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->teams->activity($locked->id, $actor->id, 'supervisor_assigned', ['supervisorUserId' => $supervisor->id]);
            return ['supervisorUserId' => $supervisor->id, 'unchanged' => false];
        });
        return response()->json(['data' => $result]);
    }

    public function decideExtension(Request $request, int $extension): JsonResponse
    {
        $actor = $this->authorizeOperations($request);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'string', 'max:500']]);
        $result = DB::transaction(function () use ($actor, $extension, $data): array {
            $row = DB::table('cleaning_supervisor_extensions')->where('id', $extension)->first();
            abort_unless($row, 404);
            $booking = CleaningBooking::query()->whereKey($row->cleaning_booking_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('cleaning_supervisor_extensions')->where('id', $extension)->lockForUpdate()->first();
            abort_unless($booking->booking_kind === SupervisedTeamService::KIND
                && $this->teams->state($booking) === 'in_progress' && $row->status === 'pending', 409);
            $approved = $data['decision'] === 'approve';
            abort_if($approved && $row->customer_decision !== 'approved', 409, 'موافقة العميل مطلوبة قبل تمديد الموعد.');
            if ($approved) {
                abort_unless(config('supervised_team.extension_approval_enabled', false), 409, 'اعتماد تسعير التمديد لم يجهز بعد.');
                $old = $booking->supervised_scheduled_end_at;
                abort_unless($old, 409);
                $newEnd = $old->copy()->addMinutes($row->additional_minutes);
                $start = Carbon::parse($booking->scheduled_date->format('Y-m-d').' '.$booking->scheduled_time);
                $conflict = DB::table('cleaning_supervisor_assignments as sa')
                    ->join('cleaning_bookings as b', 'b.id', '=', 'sa.cleaning_booking_id')
                    ->where('sa.supervisor_user_id', $row->supervisor_user_id)
                    ->where('sa.active_slot', 1)->where('b.id', '<>', $booking->id)
                    ->where('b.supervised_scheduled_end_at', '>', $start)
                    ->whereDate('b.scheduled_date', '<=', $newEnd->toDateString())
                    ->whereNotIn('b.supervised_state', ['completed', 'cancelled'])->exists();
                abort_if($conflict, 409, 'التمديد يتعارض مع موعد آخر للمشرف.');
                $booking->forceFill(['supervised_scheduled_end_at' => $newEnd])->save();
            }
            DB::table('cleaning_supervisor_extensions')->where('id', $extension)->update([
                'status' => $approved ? 'approved' : 'rejected', 'decided_by_user_id' => $actor->id,
                'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->teams->activity($booking->id, $actor->id, $approved ? 'extension_approved' : 'extension_rejected',
                ['extensionId' => $extension, 'minutes' => $row->additional_minutes]);
            return ['id' => $extension, 'status' => $approved ? 'approved' : 'rejected',
                'scheduledEndAt' => $booking->supervised_scheduled_end_at?->toIso8601String()];
        });
        return response()->json(['data' => $result]);
    }

    public function resolveObjection(Request $request, CleaningBooking $booking): JsonResponse
    {
        $actor = $this->authorizeOperations($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $summary = DB::transaction(function () use ($actor, $booking, $data): array {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->booking_kind === SupervisedTeamService::KIND && $this->teams->state($locked) === 'objected', 409);
            $locked->forceFill(['supervised_state' => 'in_progress', 'status' => CleaningBookingStatus::InProgress,
                'work_finished_at' => null])->save();
            $this->teams->activity($locked->id, $actor->id, 'objection_resolved', ['reason' => $data['reason']]);
            return $this->teams->summary($locked);
        });
        return response()->json(['data' => $summary]);
    }

    public function decideReplacement(Request $request, int $replacement): JsonResponse
    {
        $actor = $this->authorizeOperations($request);
        $data = $request->validate(['decision' => ['required', 'in:acknowledge,reject'],
            'reason' => ['nullable', 'string', 'max:1000']]);
        $result = DB::transaction(function () use ($actor, $replacement, $data): array {
            $row = DB::table('cleaning_supervisor_replacements')->where('id', $replacement)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'pending', 409);
            $status = $data['decision'] === 'acknowledge' ? 'acknowledged' : 'rejected';
            DB::table('cleaning_supervisor_replacements')->where('id', $replacement)->update([
                'status' => $status, 'decided_by_user_id' => $actor->id,
                'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->teams->activity($row->cleaning_booking_id, $actor->id, 'replacement_'.$status, ['requestId' => $replacement]);
            return ['id' => $replacement, 'status' => $status];
        });
        return response()->json(['data' => $result]);
    }
}
