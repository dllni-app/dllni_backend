<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;

final class SupervisorLifecycleController
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    public function verificationRequest(Request $request, int $booking): JsonResponse
    {
        return response()->json(['data' => $this->teams->requestVerification($request->user(), $booking)]);
    }

    public function verificationConfirm(Request $request, int $booking): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        return response()->json(['data' => $this->teams->confirmVerification($request->user(), $booking, $data['code'])]);
    }

    public function verificationStatus(Request $request, int $booking): JsonResponse
    {
        $order = $this->teams->assignedBooking($request->user(), $booking);
        $row = DB::table('cleaning_supervisor_verifications')->where('cleaning_booking_id', $booking)->first();
        return response()->json(['data' => ['state' => $this->teams->state($order),
            'expiresAt' => $row?->expires_at, 'verifiedAt' => $order->supervised_verified_at?->toIso8601String()]]);
    }

    public function start(Request $request, int $booking): JsonResponse
    {
        return response()->json(['data' => $this->teams->transition($request->user(), $booking, 'start')]);
    }

    public function handoverRequest(Request $request, int $booking): JsonResponse
    {
        return response()->json(['data' => $this->teams->transition($request->user(), $booking, 'handover')]);
    }

    public function handoverStatus(Request $request, int $booking): JsonResponse
    {
        $this->teams->assignedBooking($request->user(), $booking);
        return response()->json(['data' => DB::table('cleaning_supervisor_handovers')
            ->where('cleaning_booking_id', $booking)->first()]);
    }

    public function extensionRequest(Request $request, int $booking): JsonResponse
    {
        $order = $this->teams->assignedBooking($request->user(), $booking);
        abort_unless($this->teams->state($order) === 'in_progress', 409);
        $data = $request->validate(['additionalMinutes' => ['required', 'integer', 'min:15', 'max:240', 'multiple_of:15'],
            'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $result = DB::transaction(function () use ($request, $booking, $data): array {
            $b = $this->teams->assignedQuery($request->user())->lockForUpdate()->findOrFail($booking);
            abort_unless($this->teams->state($b) === 'in_progress', 409);
            $existing = DB::table('cleaning_supervisor_extensions')->where('cleaning_booking_id', $booking)
                ->where('status', 'pending')->lockForUpdate()->first();
            if ($existing) {
                return ['id' => $existing->id, 'status' => 'pending'];
            }
            $id = DB::table('cleaning_supervisor_extensions')->insertGetId([
                'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                'additional_minutes' => $data['additionalMinutes'], 'reason' => $data['reason'],
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->teams->activity($booking, $request->user()->id, 'extension_requested', ['extensionId' => $id]);
            return ['id' => $id, 'status' => 'pending'];
        });
        return response()->json(['data' => $result], 201);
    }

    public function extensions(Request $request, int $booking): JsonResponse
    {
        $this->teams->assignedBooking($request->user(), $booking);
        return response()->json(['data' => DB::table('cleaning_supervisor_extensions')
            ->where('cleaning_booking_id', $booking)->orderByDesc('id')->get()]);
    }

    public function report(Request $request, int $booking): JsonResponse
    {
        $data = $request->validate(['narrative' => ['required', 'string', 'min:15', 'max:10000']]);
        $summary = DB::transaction(function () use ($request, $booking, $data): array {
            $order = $this->teams->assignedQuery($request->user())->lockForUpdate()->findOrFail($booking);
            $state = $this->teams->state($order);
            abort_unless(in_array($state, ['handover_confirmed', 'completed'], true), 409);
            if ($state === 'handover_confirmed') {
                DB::table('cleaning_supervisor_reports')->insert([
                    'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                    'narrative' => $data['narrative'], 'submitted_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $order->forceFill(['supervised_state' => 'completed', 'status' => CleaningBookingStatus::Completed,
                    'customer_confirmed_at' => $order->customer_confirmed_at ?? now()])->save();
                $this->teams->activity($booking, $request->user()->id, 'report_submitted');
            }
            return $this->teams->summary($order->fresh());
        });
        return response()->json(['data' => $summary]);
    }

    public function reports(Request $request, int $booking): JsonResponse
    {
        $this->teams->assignedBooking($request->user(), $booking);
        return response()->json(['data' => DB::table('cleaning_supervisor_reports')->where('cleaning_booking_id', $booking)->first()]);
    }

    public function mediaList(Request $request, int $booking): JsonResponse
    {
        $this->teams->assignedBooking($request->user(), $booking);
        return response()->json(['data' => DB::table('cleaning_supervisor_media')->where('cleaning_booking_id', $booking)
            ->select('id', 'purpose', 'mime', 'bytes', 'created_at')->orderByDesc('id')->paginate(30)]);
    }

    public function mediaUpload(Request $request, int $booking): JsonResponse
    {
        $order = $this->teams->assignedBooking($request->user(), $booking);
        abort_unless(in_array($this->teams->state($order), ['in_progress', 'handover_pending'], true), 409);
        $data = $request->validate(['purpose' => ['required', 'in:before,during,after,incident'],
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
            'incidentId' => ['nullable', 'integer']]);
        if (isset($data['incidentId'])) {
            abort_unless(DB::table('cleaning_supervisor_incidents')->where('id', $data['incidentId'])
                ->where('cleaning_booking_id', $booking)->exists(), 422);
        }
        $img = @imagecreatefromstring(file_get_contents($data['photo']->getRealPath()));
        abort_unless($img !== false, 422);
        ob_start();
        imagejpeg($img, null, 86);
        $bytes = ob_get_clean();
        imagedestroy($img);
        abort_unless(is_string($bytes) && strlen($bytes) <= 5 * 1024 * 1024, 422);
        $path = 'supervised-team/'.$booking.'/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $bytes);
        try {
            $id = DB::table('cleaning_supervisor_media')->insertGetId([
                'cleaning_booking_id' => $booking, 'supervisor_user_id' => $request->user()->id,
                'incident_id' => $data['incidentId'] ?? null, 'purpose' => $data['purpose'],
                'disk' => 'local', 'path' => $path, 'mime' => 'image/jpeg', 'bytes' => strlen($bytes),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        $this->teams->activity($booking, $request->user()->id, 'media_uploaded', ['mediaId' => $id]);
        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function mediaDownload(Request $request, int $booking, int $media)
    {
        $this->teams->assignedBooking($request->user(), $booking);
        $row = DB::table('cleaning_supervisor_media')->where('id', $media)->where('cleaning_booking_id', $booking)->first();
        abort_unless($row && Storage::disk($row->disk)->exists($row->path), 404);
        return Storage::disk($row->disk)->response($row->path, 'photo.jpg',
            ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    public function mediaDelete(Request $request, int $booking, int $media): JsonResponse
    {
        $order = $this->teams->assignedBooking($request->user(), $booking);
        abort_unless(in_array($this->teams->state($order), ['in_progress', 'handover_pending'], true), 409);
        $row = DB::table('cleaning_supervisor_media')->where('id', $media)->where('cleaning_booking_id', $booking)->first();
        abort_unless($row && (int) $row->supervisor_user_id === (int) $request->user()->id, 404);
        DB::table('cleaning_supervisor_media')->where('id', $media)->delete();
        Storage::disk($row->disk)->delete($row->path);
        $this->teams->activity($booking, $request->user()->id, 'media_deleted', ['mediaId' => $media]);
        return response()->json(['message' => 'تم حذف الصورة.']);
    }
}
