<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Models\Dispute;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;

final class DashboardOverviewController
{
    public function __invoke(Request $request): JsonResponse
    {
        $today = Carbon::today();

        $todayCleaningBookings = CleaningBooking::query()
            ->whereDate('scheduled_date', $today)
            ->whereNotIn('status', [CleaningBookingStatus::Cancelled])
            ->count();

        $todayEventBookings = EventBooking::query()
            ->whereDate('scheduled_date', $today)
            ->whereNotIn('status', [\Modules\Cleaning\Enums\EventBookingStatus::Cancelled])
            ->count();

        $openDisputes = Dispute::query()
            ->whereIn('status', ['open', 'under_review'])
            ->count();

        $pendingWorkerAssignments = CleaningBooking::query()
            ->whereNull('worker_id')
            ->whereIn('status', [CleaningBookingStatus::Pending, CleaningBookingStatus::Confirmed])
            ->whereDate('scheduled_date', '>=', $today)
            ->count();

        $activeSosCount = SosAlert::query()
            ->whereIn('status', [SOSStatus::Triggered->value, SOSStatus::Acknowledged->value])
            ->count();

        $alerts = SystemAlert::query()
            ->with('booking')
            ->whereIn('status', [SystemAlertStatus::New->value, SystemAlertStatus::Acknowledged->value])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (SystemAlert $alert) => [
                'id' => $alert->id,
                'alertType' => $alert->alert_type?->value ?? $alert->alert_type,
                'severity' => $alert->severity?->value ?? $alert->severity,
                'status' => $alert->status?->value ?? $alert->status,
                'bookingId' => $alert->booking_id,
                'bookingType' => $alert->booking_type,
                'payload' => $alert->payload,
                'createdAt' => $alert->created_at->toDateTimeString(),
            ]);

        return response()->json([
            'kpis' => [
                'todayCleaningBookings' => $todayCleaningBookings,
                'todayEventBookings' => $todayEventBookings,
                'openDisputes' => $openDisputes,
                'pendingWorkerAssignments' => $pendingWorkerAssignments,
                'activeSosCount' => $activeSosCount,
            ],
            'alerts' => $alerts,
        ]);
    }
}
