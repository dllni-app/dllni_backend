<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;

final class SupervisorCustomerController
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    public function activate(Request $request, CleaningBooking $booking): JsonResponse
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->id, 404);
        $request->validate(['confirmTeamService' => ['required', 'accepted']]);
        return response()->json(['data' => $this->teams->summary($this->teams->initialize($booking))]);
    }

    public function verificationCode(Request $request, CleaningBooking $booking): JsonResponse
    {
        return response()->json(['data' => $this->teams->customerCode($request->user(), $booking)]);
    }

    public function handoverDecision(Request $request, CleaningBooking $booking): JsonResponse
    {
        $this->teams->assertCustomer($request->user(), $booking);
        $data = $request->validate([
            'decision' => ['required', 'in:confirm,object'],
            'objection' => ['required_if:decision,object', 'nullable', 'string', 'min:10', 'max:3000'],
        ]);
        $order = DB::transaction(function () use ($request, $booking, $data): CleaningBooking {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->teams->assertCustomer($request->user(), $locked);
            $state = $this->teams->state($locked);
            if ($state === 'handover_confirmed' && $data['decision'] === 'confirm') {
                return $locked;
            }
            abort_unless($state === 'handover_pending', 409);
            $handover = DB::table('cleaning_supervisor_handovers')->where('cleaning_booking_id', $locked->id)->lockForUpdate()->first();
            abort_unless($handover && $handover->status === 'pending', 409);
            $confirmed = $data['decision'] === 'confirm';
            DB::table('cleaning_supervisor_handovers')->where('id', $handover->id)->update([
                'status' => $confirmed ? 'confirmed' : 'objected',
                'objection' => $confirmed ? null : $data['objection'],
                'responded_at' => now(), 'updated_at' => now(),
            ]);
            $locked->forceFill([
                'supervised_state' => $confirmed ? 'handover_confirmed' : 'objected',
                'status' => $confirmed ? CleaningBookingStatus::AwaitingCustomerCompletion : CleaningBookingStatus::UnderDispute,
            ])->save();
            $this->teams->activity($locked->id, $request->user()->id, $confirmed ? 'handover_confirmed' : 'handover_objected');
            return $locked;
        });
        return response()->json(['data' => $this->teams->summary($order->fresh())]);
    }

    public function extensionDecision(Request $request, CleaningBooking $booking, int $extension): JsonResponse
    {
        $this->teams->assertCustomer($request->user(), $booking);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $result = DB::transaction(function () use ($request, $booking, $extension, $data): array {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->teams->assertCustomer($request->user(), $locked);
            $row = DB::table('cleaning_supervisor_extensions')->where('id', $extension)
                ->where('cleaning_booking_id', $locked->id)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'pending' && ! $row->customer_decided_at, 409);
            $approved = $data['decision'] === 'approve';
            DB::table('cleaning_supervisor_extensions')->where('id', $extension)->update([
                'customer_decision' => $approved ? 'approved' : 'rejected',
                'customer_decided_at' => now(),
                'status' => $approved ? 'pending' : 'rejected',
                'updated_at' => now(),
            ]);
            $this->teams->activity($locked->id, $request->user()->id, $approved ? 'extension_customer_approved' : 'extension_customer_rejected',
                ['extensionId' => $extension]);
            return ['id' => $extension, 'status' => $approved ? 'pending_operations' : 'rejected'];
        });
        return response()->json(['data' => $result]);
    }
}
