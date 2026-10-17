<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SupervisorWorkflowController
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    public function verificationRequest(Request $request, int $booking): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $booking): array {
            $order = $this->assigned($request, $booking, true);
            $attendance = $this->teams->attendance($order);
            $this->failUnless($attendance['teamReady'], 'يجب حضور الفريق كاملاً قبل التحقق.');
            $this->failUnless(in_array($order->status, [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::AwaitingStartVerification,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation,
            ], true), 'الطلب ليس في مرحلة التحقق.');
            $existing = DB::table('cleaning_supervisor_verifications')
                ->where('cleaning_booking_id', $booking)->lockForUpdate()->first();
            if ($existing && $existing->status === 'verified') {
                return ['status' => 'verified', 'verifiedAt' => $existing->verified_at];
            }
            if ($existing && $existing->status === 'pending' && Carbon::parse($existing->expires_at)->isFuture()
                && $existing->attempts < 5) {
                return ['status' => 'pending', 'expiresAt' => $existing->expires_at];
            }
            $code = (string) random_int(100000, 999999);
            $attributes = [
                'supervisor_user_id' => $request->user()->id,
                'code_hash' => Hash::make($code),
                'customer_code_ciphertext' => Crypt::encryptString($code),
                'status' => 'pending',
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'verified_at' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('cleaning_supervisor_verifications')->where('id', $existing->id)->update($attributes);
            } else {
                DB::table('cleaning_supervisor_verifications')->insert($attributes + [
                    'cleaning_booking_id' => $booking, 'created_at' => now(),
                ]);
            }
            $order->forceFill(['status' => CleaningBookingStatus::AwaitingStartVerification,
                'supervised_state' => 'verification_pending'])->save();
            $this->teams->activity($booking, $request->user()->id, 'verification_requested');

            return ['status' => 'pending', 'expiresAt' => now()->addMinutes(10)->toIso8601String()];
        });

        // The secret is deliberately NOT returned to the supervisor. Only the authenticated customer may read it.
        return $this->response($result);
    }

    public function verificationStatus(Request $request, int $booking): JsonResponse
    {
        $order = $this->assigned($request, $booking);
        $session = DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $booking)->first();

        return $this->response([
            'status' => ! $session ? 'not_requested' :
                ($session->status === 'pending' && ! Carbon::parse($session->expires_at)->isFuture() ? 'expired' : $session->status),
            'expiresAt' => $session?->expires_at, 'verifiedAt' => $session?->verified_at,
            'teamState' => $order->supervised_state ?: 'staffing',
            'entryActor' => 'supervisor', 'codeRecipient' => 'customer',
        ]);
    }

    public function customerVerificationCode(Request $request, int $booking): JsonResponse
    {
        $order = $this->customer($request, $booking);
        $session = DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $booking)->first();
        abort_unless($order->status === CleaningBookingStatus::AwaitingStartVerification &&
            $session && $session->status === 'pending' &&
            Carbon::parse($session->expires_at)->isFuture() && $session->attempts < 5, 404);

        return $this->response([
            'code' => Crypt::decryptString($session->customer_code_ciphertext),
            'expiresAt' => Carbon::parse($session->expires_at)->toIso8601String(),
            'purpose' => 'supervisor_start_verification',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function customerShow(Request $request, int $booking): JsonResponse
    {
        $order = $this->customer($request, $booking);
        $verification = DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $booking)->first();
        $extensions = DB::table('cleaning_supervisor_extensions')->where('cleaning_booking_id', $booking)
            ->orderByDesc('id')->get(['id', 'requested_minutes as additionalMinutes', 'reason', 'status']);
        $handover = DB::table('cleaning_supervisor_handovers')->where('cleaning_booking_id', $booking)->first();
        $actions = [];
        if ($order->status === CleaningBookingStatus::AwaitingStartVerification && $verification
            && $verification->status === 'pending' && $verification->attempts < 5
            && Carbon::parse($verification->expires_at)->isFuture()) {
            $actions[] = 'view_verification_code';
        }
        if ($order->status === CleaningBookingStatus::InProgress && $extensions->contains('status', 'pending')) {
            $actions[] = 'decide_extension';
        }
        if ($order->status === CleaningBookingStatus::AwaitingCustomerCompletion && $handover?->status === 'pending') {
            $actions[] = 'decide_handover';
        }

        return $this->response([
            'booking' => array_replace($this->teams->summary($order), ['allowedActions' => $actions]),
            'extensions' => $extensions->toArray(),
            'handover' => ['status' => $handover?->status ?? 'not_requested',
                'customerNote' => $handover?->customer_note, 'respondedAt' => $handover?->responded_at],
            'allowedActions' => $actions,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function verificationConfirm(Request $request, int $booking): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $result = DB::transaction(function () use ($request, $booking, $data): array {
            $order = $this->assigned($request, $booking, true);
            $this->failUnless($order->status === CleaningBookingStatus::AwaitingStartVerification
                && $order->supervised_state === 'verification_pending', 'الطلب ليس في مرحلة التحقق.');
            $session = DB::table('cleaning_supervisor_verifications')
                ->where('cleaning_booking_id', $booking)->lockForUpdate()->first();
            if (! $session || $session->status !== 'pending' ||
                ! Carbon::parse($session->expires_at)->isFuture() || $session->attempts >= 5) {
                return ['status' => 'expired'];
            }
            if (! Hash::check((string) $data['code'], $session->code_hash)) {
                DB::table('cleaning_supervisor_verifications')->where('id', $session->id)
                    ->update(['attempts' => $session->attempts + 1, 'updated_at' => now()]);

                return ['status' => 'invalid'];
            }
            $this->failUnless($this->teams->attendance($order)['teamReady'], 'لم يعد الفريق جاهزاً.');
            DB::table('cleaning_supervisor_verifications')->where('id', $session->id)
                ->update(['status' => 'verified', 'verified_at' => now(),
                    'customer_code_ciphertext' => '', 'updated_at' => now()]);
            $order->forceFill(['status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation,
                'supervised_state' => 'verified'])->save();
            $this->teams->activity($booking, $request->user()->id, 'verification_confirmed');

            return ['status' => 'verified', 'verifiedAt' => now()->toIso8601String()];
        });
        if ($result['status'] !== 'verified') {
            return response()->json(['code' => 'INVALID_OR_EXPIRED_CODE',
                'message' => 'رمز غير صحيح أو منتهي الصلاحية.'], 422);
        }

        return $this->response($result);
    }

    public function start(Request $request, int $booking): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $booking): array {
            $order = $this->assigned($request, $booking, true);
            if ($order->status === CleaningBookingStatus::InProgress && $order->work_started_at !== null) {
                return $this->teams->summary($order);
            }
            $this->failUnless($order->supervised_state === 'verified' &&
                $order->status === CleaningBookingStatus::AwaitingWorkerStartConfirmation, 'يجب التحقق قبل البدء.');
            $this->failUnless($this->teams->attendance($order)['teamReady'], 'حضور الفريق غير مكتمل.');
            $minutes = max(1, (int) ($order->supervised_duration_minutes ?: ceil(
                ((float) $order->estimated_hours * 60) / max(1, (int) $order->number_of_workers)
            )));
            $end = $order->work_started_at
                ? Carbon::parse($order->work_started_at)->addMinutes($minutes)
                : now()->addMinutes($minutes);
            $order->forceFill([
                'status' => CleaningBookingStatus::InProgress, 'supervised_state' => 'in_progress',
                'work_started_at' => $order->work_started_at ?? now(),
                'supervised_duration_minutes' => $minutes,
                'supervised_original_end_at' => $order->supervised_original_end_at ?? $end,
                'supervised_scheduled_end_at' => $end,
            ])->save();
            // One supervisor action starts the team, not independent worker start buttons.
            $order->workerAssignments()
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->whereNotNull('arrived_at')
                ->update(['status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
                    'work_started_at' => now(), 'start_approved_at' => now(), 'updated_at' => now()]);
            $this->teams->activity($booking, $request->user()->id, 'work_started', ['durationMinutes' => $minutes]);

            return $this->teams->summary($order->fresh());
        });

        return $this->response($result);
    }

    public function extensionIndex(Request $request, int $booking): JsonResponse
    {
        $this->assigned($request, $booking);

        return $this->response(DB::table('cleaning_supervisor_extensions')->where('cleaning_booking_id', $booking)
            ->orderByDesc('id')->get(['id', 'requested_minutes as additional_minutes', 'reason', 'status',
                'decided_at as decidedAt', 'created_at as createdAt'])->toArray());
    }

    public function extensionRequest(Request $request, int $booking): JsonResponse
    {
        $request->merge(['minutes' => $request->input('minutes', $request->input('additionalMinutes'))]);
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:15', 'max:240', 'multiple_of:15'],
            'reason' => ['required', 'string', 'min:5', 'max:1500']]);
        $result = DB::transaction(function () use ($request, $booking, $data): array {
            $order = $this->assigned($request, $booking, true);
            $this->failUnless($order->status === CleaningBookingStatus::InProgress, 'طلب التمديد مسموح أثناء التنفيذ فقط.');
            $existing = DB::table('cleaning_supervisor_extensions')
                ->where('cleaning_booking_id', $booking)->where('status', 'pending')->lockForUpdate()->first();
            if ($existing) {
                return ['id' => $existing->id, 'status' => 'pending'];
            }
            $id = DB::table('cleaning_supervisor_extensions')->insertGetId([
                'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                'requested_minutes' => $data['minutes'], 'reason' => $data['reason'],
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->teams->activity($booking, $request->user()->id, 'extension_requested',
                ['requestId' => $id, 'minutes' => $data['minutes']]);

            return ['id' => $id, 'status' => 'pending'];
        });

        return $this->response($result, 201);
    }

    public function extensionDecision(Request $request, int $booking, int $extension): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);
        $result = DB::transaction(function () use ($request, $booking, $extension, $data): array {
            $order = $this->customer($request, $booking, true);
            $record = DB::table('cleaning_supervisor_extensions')->where('id', $extension)
                ->where('cleaning_booking_id', $booking)->lockForUpdate()->first();
            abort_unless($record, 404);
            if ($record->status !== 'pending') {
                return ['id' => $record->id, 'status' => $record->status];
            }
            $this->failUnless($order->status === CleaningBookingStatus::InProgress, 'لم يعد التمديد متاحاً.');
            DB::table('cleaning_supervisor_extensions')->where('id', $extension)->update([
                'status' => $data['decision'], 'decided_by_user_id' => $request->user()->id,
                'decided_at' => now(), 'updated_at' => now(),
            ]);
            if ($data['decision'] === 'approved') {
                $end = $order->supervised_scheduled_end_at ?: now();
                $order->forceFill([
                    'supervised_scheduled_end_at' => Carbon::parse($end)->addMinutes($record->requested_minutes),
                    'supervised_duration_minutes' => (int) $order->supervised_duration_minutes + $record->requested_minutes,
                ])->save();
            }
            $this->teams->activity($booking, $request->user()->id, 'extension_'.$data['decision'],
                ['requestId' => $extension]);

            return ['id' => $extension, 'status' => $data['decision']];
        });

        return $this->response($result);
    }

    public function handoverRequest(Request $request, int $booking): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $booking): array {
            $order = $this->assigned($request, $booking, true);
            $existing = DB::table('cleaning_supervisor_handovers')->where('cleaning_booking_id', $booking)->first();
            if ($existing) {
                return ['id' => $existing->id, 'status' => $existing->status];
            }
            $this->failUnless($order->status === CleaningBookingStatus::InProgress, 'التسليم متاح أثناء التنفيذ فقط.');
            $pendingExtension = DB::table('cleaning_supervisor_extensions')
                ->where('cleaning_booking_id', $booking)->where('status', 'pending')->exists();
            $this->failUnless(! $pendingExtension, 'يجب إنهاء قرار التمديد أولاً.');
            $id = DB::table('cleaning_supervisor_handovers')->insertGetId([
                'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $order->forceFill(['status' => CleaningBookingStatus::AwaitingCustomerCompletion,
                'supervised_state' => 'handover_pending', 'work_finished_at' => now()])->save();
            $this->teams->activity($booking, $request->user()->id, 'handover_requested');

            return ['id' => $id, 'status' => 'pending'];
        });

        return $this->response($result, 201);
    }

    public function handoverStatus(Request $request, int $booking): JsonResponse
    {
        $this->assigned($request, $booking);
        $handover = DB::table('cleaning_supervisor_handovers')->where('cleaning_booking_id', $booking)->first();

        return $this->response(['status' => $handover?->status ?? 'not_requested',
            'respondedAt' => $handover?->responded_at, 'customerNote' => $handover?->customer_note]);
    }

    public function handoverDecision(Request $request, int $booking): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:confirmed,objected'],
            'note' => ['required_if:decision,objected', 'nullable', 'string', 'min:5', 'max:2000']]);
        $result = DB::transaction(function () use ($request, $booking, $data): array {
            $order = $this->customer($request, $booking, true);
            $handover = DB::table('cleaning_supervisor_handovers')
                ->where('cleaning_booking_id', $booking)->lockForUpdate()->first();
            abort_unless($handover, 404);
            if ($handover->status !== 'pending') {
                return ['status' => $handover->status, 'respondedAt' => $handover->responded_at];
            }
            $this->failUnless($order->status === CleaningBookingStatus::AwaitingCustomerCompletion, 'الطلب ليس بانتظار الاستلام.');
            DB::table('cleaning_supervisor_handovers')->where('id', $handover->id)->update([
                'status' => $data['decision'], 'customer_note' => $data['note'] ?? null,
                'responded_at' => now(), 'updated_at' => now(),
            ]);
            $order->forceFill($data['decision'] === 'confirmed' ? [
                'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
                'supervised_state' => 'handover_confirmed', 'customer_confirmed_at' => now(),
            ] : [
                'status' => CleaningBookingStatus::UnderDispute,
                'supervised_state' => 'handover_objected',
                'customer_completion_rejection_message' => $data['note'],
                'completion_rejected_at' => now(),
            ])->save();
            $this->teams->activity($booking, $request->user()->id, 'handover_'.$data['decision']);

            return ['status' => $data['decision'], 'respondedAt' => now()->toIso8601String()];
        });

        return $this->response($result);
    }

    public function reportShow(Request $request, int $booking): JsonResponse
    {
        $this->assigned($request, $booking);
        $report = DB::table('cleaning_supervisor_reports')->where('cleaning_booking_id', $booking)->first();

        return $this->response($report ? [
            'id' => $report->id, 'summary' => $report->summary, 'narrative' => $report->summary,
            'qualityNotes' => $report->quality_notes,
            'status' => 'submitted', 'submittedAt' => $report->submitted_at,
        ] : ['status' => 'not_submitted']);
    }

    public function reportSubmit(Request $request, int $booking): JsonResponse
    {
        $request->merge(['summary' => $request->input('summary', $request->input('narrative'))]);
        $data = $request->validate(['summary' => ['required', 'string', 'min:10', 'max:8000'],
            'qualityNotes' => ['nullable', 'string', 'max:4000']]);
        $result = DB::transaction(function () use ($request, $booking, $data): array {
            $order = $this->assigned($request, $booking, true);
            $existing = DB::table('cleaning_supervisor_reports')->where('cleaning_booking_id', $booking)->first();
            if ($existing) {
                return ['id' => $existing->id, 'status' => 'submitted', 'unchanged' => true];
            }
            $this->failUnless($order->status === CleaningBookingStatus::AwaitingCustomerCompletion &&
                $order->supervised_state === 'handover_confirmed' &&
                $order->customer_confirmed_at !== null, 'يجب انتظار تأكيد العميل قبل اعتماد التقرير.');
            $id = DB::table('cleaning_supervisor_reports')->insertGetId([
                'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                'summary' => $data['summary'], 'quality_notes' => $data['qualityNotes'] ?? null,
                'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $order->forceFill(['status' => CleaningBookingStatus::Completed,
                'supervised_state' => 'completed', 'work_finished_at' => $order->work_finished_at ?? now()])->save();
            $order->workerAssignments()->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->update(['status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
                    'work_finished_at' => $order->work_finished_at, 'updated_at' => now()]);
            $this->teams->activity($booking, $request->user()->id, 'report_submitted', ['reportId' => $id]);

            return ['id' => $id, 'status' => 'submitted'];
        });

        return $this->response($result, ($result['unchanged'] ?? false) ? 200 : 201);
    }

    public function mediaIndex(Request $request, int $booking): JsonResponse
    {
        $this->assigned($request, $booking);

        return $this->response(DB::table('cleaning_supervisor_media')->where('cleaning_booking_id', $booking)
            ->orderByDesc('id')->get(['id', 'purpose', 'mime_type as mimeType', 'bytes',
                'created_at as createdAt'])->toArray());
    }

    public function mediaUpload(Request $request, int $booking): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'in:before,during,after,incident'],
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);
        $order = $this->assigned($request, $booking);
        abort_if(in_array($order->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true), 409);
        $file = $data['photo'];
        $path = $file->store('private/supervisor-media/'.$booking, 'local');
        $id = DB::table('cleaning_supervisor_media')->insertGetId([
            'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
            'purpose' => $data['purpose'], 'disk' => 'local', 'path' => $path,
            'mime_type' => $file->getMimeType(), 'bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->teams->activity($booking, $request->user()->id, 'media_uploaded', [
            'mediaId' => $id, 'purpose' => $data['purpose'],
        ]);

        return $this->response(['id' => $id, 'purpose' => $data['purpose']], 201);
    }

    public function mediaShow(Request $request, int $booking, int $media): StreamedResponse
    {
        $this->assigned($request, $booking);
        $entry = DB::table('cleaning_supervisor_media')->where('id', $media)
            ->where('cleaning_booking_id', $booking)->first();
        abort_unless($entry, 404);
        abort_unless(Storage::disk($entry->disk)->exists($entry->path), 404);

        return Storage::disk($entry->disk)->response($entry->path, 'evidence-'.$media, [
            'Content-Type' => $entry->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assigned(Request $request, int $id, bool $lock = false): CleaningBooking
    {
        return $this->teams->assignedBooking($request->user(), $id, $lock);
    }

    private function customer(Request $request, int $id, bool $lock = false): CleaningBooking
    {
        $query = CleaningBooking::query()->whereKey($id)->where('booking_kind', SupervisedTeamService::KIND)
            ->where('customer_id', $request->user()?->id);
        $result = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($result && $request->user()?->is_active, 404);

        return $result;
    }

    private function failUnless(bool $condition, string $message): void
    {
        abort_unless($condition, 409, $message);
    }

    private function response(array $data, int $code = 200): JsonResponse
    {
        return response()->json(['data' => $data], $code);
    }
}
