<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;

final class BookingWorkerAttendanceCodeController
{
    public function issue(Request $request, int $booking): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $booking): array {
            $worker = $request->user()?->worker;
            abort_unless($worker && $request->user()->is_active, 403);
            $order = CleaningBooking::query()->whereKey($booking)
                ->whereIn('booking_kind', ['standard', SupervisedTeamService::KIND])
                ->where('number_of_workers', '>', 1)
                ->lockForUpdate()->firstOrFail();
            abort_unless(in_array($order->status, [CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::AwaitingStartVerification], true), 409);
            $assignment = $order->workerAssignments()->where('worker_id', $worker->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->lockForUpdate()->firstOrFail();
            abort_unless($assignment->arrived_at !== null, 409, 'Worker must arrive before requesting attendance proof.');
            $proof = DB::table('cleaning_worker_attendance_proofs')->where('worker_assignment_id', $assignment->id)
                ->lockForUpdate()->first();
            if ($proof?->confirmed_at) {
                return ['status' => 'confirmed', 'confirmedAt' => $proof->confirmed_at];
            }
            if ($proof && Carbon::parse($proof->expires_at)->isFuture() && $proof->attempts < 5) {
                return ['status' => 'pending', 'code' => Crypt::decryptString($proof->worker_code_ciphertext),
                    // Database datetimes contain no timezone offset (stored in
                    // UTC). Always return an ISO timestamp with an offset just
                    // like the initial issue response, or Flutter formats the
                    // existing code as three hours earlier on Syrian devices.
                    'expiresAt' => Carbon::parse($proof->expires_at, 'UTC')->toIso8601String()];
            }
            $code = (string) random_int(100000, 999999);
            $values = ['cleaning_booking_id' => $booking, 'code_hash' => Hash::make($code),
                'worker_code_ciphertext' => Crypt::encryptString($code), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'confirmed_at' => null,
                'confirmed_by_user_id' => null, 'updated_at' => now()];
            if ($proof) {
                DB::table('cleaning_worker_attendance_proofs')->where('id', $proof->id)->update($values);
            } else {
                DB::table('cleaning_worker_attendance_proofs')->insert($values +
                    ['worker_assignment_id' => $assignment->id, 'created_at' => now()]);
            }

            return ['status' => 'pending', 'code' => $code, 'expiresAt' => now()->addMinutes(10)->toIso8601String()];
        });

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, int $booking, int $assignment): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $result = DB::transaction(function () use ($request, $booking, $assignment, $data): string {
            $teams = app(SupervisedTeamService::class);
            $order = $teams->assignedBooking($request->user(), $booking, true);
            abort_unless(in_array($order->status, [CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::AwaitingStartVerification], true), 409);
            $workerAssignment = $order->workerAssignments()->whereKey($assignment)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->lockForUpdate()->firstOrFail();
            abort_unless($workerAssignment->arrived_at !== null, 409);
            $proof = DB::table('cleaning_worker_attendance_proofs')
                ->where('cleaning_booking_id', $booking)->where('worker_assignment_id', $assignment)
                ->lockForUpdate()->first();
            if ($proof?->confirmed_at) {
                return 'confirmed';
            }
            if (! $proof || $proof->attempts >= 5 || ! Carbon::parse($proof->expires_at)->isFuture()) {
                return 'expired';
            }
            if (! Hash::check($data['code'], $proof->code_hash)) {
                DB::table('cleaning_worker_attendance_proofs')->where('id', $proof->id)
                    ->update(['attempts' => $proof->attempts + 1, 'updated_at' => now()]);

                return 'invalid';
            }
            DB::table('cleaning_worker_attendance_proofs')->where('id', $proof->id)
                ->update(['confirmed_at' => now(), 'confirmed_by_user_id' => $request->user()->id,
                    'worker_code_ciphertext' => '', 'updated_at' => now()]);
            $teams->activity($booking, $request->user()->id, 'worker_attendance_confirmed',
                ['assignmentId' => $assignment]);

            return 'confirmed';
        });

        return $result === 'confirmed'
            ? response()->json(['data' => ['status' => 'confirmed']])
            : response()->json(['code' => 'INVALID_OR_EXPIRED_CODE', 'message' => 'Invalid or expired attendance code.'], 422);
    }
}
