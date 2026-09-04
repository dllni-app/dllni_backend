from pathlib import Path


def replace_once(path: str, old: str, new: str, label: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected 1 match, found {count}")
    p.write_text(text.replace(old, new, 1))


# Add a terminal audit-only session status for visits replaced by a confirmed revision.
replace_once(
    "Modules/Cleaning/app/Enums/CleaningBookingSessionStatus.php",
    "    case Paused = 'paused';\n",
    "    case Paused = 'paused';\n    case Superseded = 'superseded';\n",
    "superseded enum case",
)
replace_once(
    "Modules/Cleaning/app/Enums/CleaningBookingSessionStatus.php",
    "            self::Skipped->value,\n        ];\n",
    "            self::Skipped->value,\n            self::Superseded->value,\n        ];\n",
    "superseded terminal value",
)
replace_once(
    "Modules/Cleaning/app/Enums/CleaningBookingSessionStatus.php",
    "            self::Paused => 'متوقفة مؤقتاً',\n",
    "            self::Paused => 'متوقفة مؤقتاً',\n            self::Superseded => 'مستبدلة بتعديل أحدث',\n",
    "superseded label",
)

# Superseded sessions stay in the database for audit, but are not part of the active schedule contract.
replace_once(
    "Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php",
    "            ->where('cleaning_booking_id', $booking->id)\n            ->with(['workerAssignments.worker.user'])\n",
    "            ->where('cleaning_booking_id', $booking->id)\n            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)\n            ->with(['workerAssignments.worker.user'])\n",
    "presenter hides superseded",
)

# Shared parent aggregation excludes superseded audit rows and preserves an existing coupon discount.
replace_once(
    "Modules/Cleaning/app/Services/CleaningBookingSessionFinancialAggregationService.php",
    "                CleaningBookingSessionStatus::Skipped->value,\n            ], true);\n",
    "                CleaningBookingSessionStatus::Skipped->value,\n                CleaningBookingSessionStatus::Superseded->value,\n            ], true);\n",
    "aggregation excludes superseded",
)
replace_once(
    "Modules/Cleaning/app/Services/CleaningBookingSessionFinancialAggregationService.php",
    "        $totalHours = round((float) $chargeable->sum('duration_hours'), 2);\n\n        CleaningBooking::query()\n            ->whereKey($booking->id)\n            ->lockForUpdate()\n            ->firstOrFail()\n            ->forceFill([\n",
    "        $totalHours = round((float) $chargeable->sum('duration_hours'), 2);\n        $grossTotal = round($serviceTotal + $cancellationFee, 2);\n        $lockedBooking = CleaningBooking::query()\n            ->whereKey($booking->id)\n            ->lockForUpdate()\n            ->firstOrFail();\n        $discount = min($grossTotal, max(0.0, (float) ($lockedBooking->discount_amount ?? 0)));\n\n        $lockedBooking\n            ->forceFill([\n",
    "aggregation locks booking before discount",
)
replace_once(
    "Modules/Cleaning/app/Services/CleaningBookingSessionFinancialAggregationService.php",
    "                'cancellation_fee' => $cancellationFee,\n                'total_hours' => $totalHours,\n                'total_price' => round($serviceTotal + $cancellationFee, 2),\n",
    "                'cancellation_fee' => $cancellationFee,\n                'total_hours' => $totalHours,\n                'subtotal_before_discount' => $discount > 0 || $lockedBooking->subtotal_before_discount !== null\n                    ? $grossTotal\n                    : null,\n                'discount_amount' => $discount,\n                'total_price' => round($grossTotal - $discount, 2),\n",
    "aggregation reapplies discount",
)

request = r'''<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

final class UserRecurringCleaningScheduleRevisionRequest extends FormRequest
{
    private const MAX_WINDOW_DAYS = 30;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule' => ['required', 'array:mode,sessions'],
            'schedule.mode' => ['required', 'string', Rule::in(['recurring'])],
            'schedule.sessions' => ['required', 'array', 'min:1'],
            'schedule.sessions.*' => ['required', 'array:date,time'],
            'schedule.sessions.*.date' => ['required', 'date', 'after_or_equal:'.now(config('app.timezone'))->toDateString()],
            'schedule.sessions.*.time' => ['required', 'date_format:H:i'],
            'schedule.sessions.*.hours' => ['prohibited'],
            'revisionToken' => ['sometimes', 'string', 'size:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sessions = $this->input('schedule.sessions');
            if (! is_array($sessions)) {
                return;
            }

            $slots = [];
            $dates = [];
            $now = CarbonImmutable::now(config('app.timezone'));

            foreach ($sessions as $index => $session) {
                if (! is_array($session)) {
                    continue;
                }

                $date = mb_trim((string) ($session['date'] ?? ''));
                $time = mb_trim((string) ($session['time'] ?? ''));
                if ($date === '' || $time === '') {
                    continue;
                }

                $slot = $date.'|'.$time;
                if (isset($slots[$slot])) {
                    $validator->errors()->add("schedule.sessions.{$index}.time", 'لا يمكن إضافة زيارتين دوريتين في نفس التاريخ والوقت.');
                }
                $slots[$slot] = true;

                try {
                    $startsAt = CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
                    $dates[] = $startsAt->startOfDay();
                    if (! $startsAt->gt($now)) {
                        $validator->errors()->add("schedule.sessions.{$index}.time", 'يجب أن يكون موعد الزيارة المعدلة في المستقبل.');
                    }
                } catch (Throwable) {
                    // Base date/time rules own malformed values.
                }
            }

            if (count($dates) >= 2) {
                usort($dates, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp());
                if ($dates[0]->diffInDays($dates[count($dates) - 1]) > self::MAX_WINDOW_DAYS) {
                    $validator->errors()->add('schedule.sessions', 'يجب أن تقع جميع الزيارات المستقبلية المعدلة ضمن فترة لا تتجاوز 30 يوماً.');
                }
            }
        });
    }
}
'''
Path("Modules/User/app/Http/Requests/UserRecurringCleaningScheduleRevisionRequest.php").write_text(request)

service = r'''<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingSessionFinancialAggregationService;
use Modules\Cleaning\Services\CleaningBookingSessionParentStateService;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Throwable;

final class RecurringCleaningScheduleRevisionService
{
    public function __construct(
        private readonly UserCleaningOrderEstimationService $estimationService,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
        private readonly CleaningBookingSessionParentStateService $parentState,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    /** @param array<string,mixed> $schedule */
    public function preview(CleaningBooking $booking, int $customerId, array $schedule): array
    {
        $this->assertCustomer($booking, $customerId);
        $built = $this->build($booking, $schedule, false);

        return $built['preview'];
    }

    /** @param array<string,mixed> $schedule */
    public function confirm(CleaningBooking $booking, int $customerId, array $schedule, string $revisionToken): array
    {
        $this->assertCustomer($booking, $customerId);
        $releasedWorkerIds = [];
        $supersededSessionIds = [];
        $createdSessionIds = [];
        $confirmedPreview = [];

        DB::transaction(function () use (
            $booking,
            $schedule,
            $revisionToken,
            &$releasedWorkerIds,
            &$supersededSessionIds,
            &$createdSessionIds,
            &$confirmedPreview,
        ): void {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $built = $this->build($lockedBooking, $schedule, true);
            $confirmedPreview = $built['preview'];

            if (! hash_equals((string) $confirmedPreview['revisionToken'], mb_trim($revisionToken))) {
                throw ValidationException::withMessages([
                    'revisionToken' => ['تغيّرت حالة الحجز أو الأسعار. أعد معاينة التعديل قبل التأكيد.'],
                ]);
            }

            /** @var Collection<int,CleaningBookingSession> $editable */
            $editable = $built['editable'];
            $releasedAt = now();
            $maxSequence = max(0, (int) CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->max('sequence'));

            foreach ($editable->values() as $index => $session) {
                $assignments = CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->lockForUpdate()
                    ->get();

                foreach ($assignments as $assignment) {
                    $workerId = (int) $assignment->worker_id;
                    if ($workerId > 0) {
                        $releasedWorkerIds[] = $workerId;
                    }
                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                        'released_at' => $releasedAt,
                        'released_reason' => 'Customer confirmed a recurring schedule revision.',
                    ])->save();
                }

                $session->forceFill([
                    'sequence' => $maxSequence + 1000 + $index + 1,
                    'status' => CleaningBookingSessionStatus::Superseded,
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'cancellation_fee' => 0,
                    'version' => max(1, (int) $session->version) + 1,
                ])->save();
                $supersededSessionIds[] = (int) $session->id;
            }

            foreach ($built['proposedSessions'] as $index => $session) {
                $created = CleaningBookingSession::query()->create([
                    'cleaning_booking_id' => $lockedBooking->id,
                    'sequence' => $maxSequence + 2000 + $index + 1,
                    'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
                    'calculation_mode' => 'estimated_hours',
                    'scheduled_date' => $session['date'],
                    'scheduled_time' => $session['time'],
                    'duration_hours' => $built['sessionHours'],
                    'required_workers' => max(1, (int) $lockedBooking->number_of_workers),
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'status' => CleaningBookingSessionStatus::Scheduled,
                    'base_price' => $built['singleVisitPricing']['basePrice'],
                    'addons_total' => $built['singleVisitPricing']['addonsTotal'],
                    'materials_total' => 0,
                    'special_services_total' => 0,
                    'travel_fee' => $built['singleVisitPricing']['travelFee'],
                    'travel_distance_km' => $built['singleVisitPricing']['distanceKm'],
                    'admin_margin_amount' => $built['singleVisitPricing']['adminMargin'],
                    'extension_fee_total' => 0,
                    'cancellation_fee' => 0,
                    'total_price' => $built['singleVisitPricing']['totalPrice'],
                    'is_pricing_final' => $built['singleVisitPricing']['isPricingFinal'],
                    'pricing_snapshot' => [
                        'scheduleType' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
                        'scheduleMode' => 'multi_day',
                        'revisedAt' => now()->toIso8601String(),
                        'revisionToken' => $confirmedPreview['revisionToken'],
                        'pricingAlgorithmVersion' => $this->estimationService->algorithmVersion(),
                        'perVisitEstimatedHours' => $built['sessionHours'],
                        'requiredWorkers' => max(1, (int) $lockedBooking->number_of_workers),
                        'currency' => (string) ($built['singleVisitPricing']['currency'] ?? config('app.currency', 'SYP')),
                    ],
                    'version' => 1,
                ]);
                $createdSessionIds[] = (int) $created->id;
            }

            $this->resequenceVisibleSessions($lockedBooking);
            $this->financialAggregation->sync($lockedBooking);

            $visible = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
                ->orderBy('scheduled_date')
                ->orderBy('scheduled_time')
                ->orderBy('id')
                ->get();
            $first = $visible->first();
            $chargeable = $visible->reject(fn (CleaningBookingSession $session): bool => in_array(
                $this->status($session),
                [CleaningBookingSessionStatus::Cancelled->value, CleaningBookingSessionStatus::Skipped->value],
                true,
            ));

            $lockedBooking->refresh();
            $lockedBooking->forceFill([
                'scheduled_date' => $first?->scheduled_date,
                'scheduled_time' => $first?->scheduled_time,
                'estimated_hours' => round((float) $chargeable->sum('duration_hours'), 2),
                'is_pricing_final' => (bool) $built['singleVisitPricing']['isPricingFinal'],
            ])->saveQuietly();
        }, 3);

        $this->parentState->refresh($booking);
        $fresh = $booking->fresh(['customer']) ?? $booking;
        $releasedWorkerIds = array_values(array_unique($releasedWorkerIds));

        foreach ($releasedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $fresh,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: 'customer_revised_recurring_schedule',
                actorRole: 'customer',
                occurredAt: now()->toIso8601String(),
                extraData: [
                    'supersededSessionIds' => $supersededSessionIds,
                    'createdSessionIds' => $createdSessionIds,
                ],
            );
        }

        return [
            'booking' => $fresh,
            'revision' => [
                ...$confirmedPreview,
                'applied' => true,
                'supersededSessionIds' => $supersededSessionIds,
                'createdSessionIds' => $createdSessionIds,
                'releasedWorkerIds' => $releasedWorkerIds,
            ],
        ];
    }

    /** @param array<string,mixed> $schedule */
    private function build(CleaningBooking $booking, array $schedule, bool $lock): array
    {
        $this->assertRecurringAndEditable($booking);
        $proposedSessions = $this->normalizeProposedSessions($schedule);
        $sessions = $this->sessions($booking, $lock);
        $editable = $sessions->filter(fn (CleaningBookingSession $session): bool => $this->isEditableFutureSession($session))->values();

        if ($editable->isEmpty()) {
            throw ValidationException::withMessages([
                'schedule' => ['لا توجد زيارات دورية مستقبلية قابلة للتعديل.'],
            ]);
        }

        $visibleWithoutEditable = $sessions->reject(
            fn (CleaningBookingSession $session): bool => $editable->contains('id', $session->id),
        )->values();
        if ($visibleWithoutEditable->count() + count($proposedSessions) < 2) {
            throw ValidationException::withMessages([
                'schedule.sessions' => ['يجب أن يبقى الحجز الدوري مكوّناً من زيارتين على الأقل إجمالاً.'],
            ]);
        }

        $preferredWorkerId = $booking->resolvedAssignmentMode() === 'preferred_worker'
            ? $booking->preferred_worker_id
            : null;
        try {
            $singleVisitPricing = $this->estimationService->price(
                (string) $booking->property_type,
                (array) ($booking->property_details ?? []),
                $booking->address_latitude,
                $booking->address_longitude,
                $preferredWorkerId,
            );
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'schedule' => ['تعذر إعادة تسعير الزيارات المستقبلية. حدّث بيانات الحجز وحاول مرة أخرى.'],
            ]);
        }
        $estimate = $this->estimationService->estimate(
            (string) $booking->property_type,
            (array) ($booking->property_details ?? []),
        );
        $sessionHours = round(max(0.01, (float) ($estimate['estimatedHours'] ?? $editable->first()->duration_hours ?? 1)), 2);

        $immutableChargeableTotal = round((float) $visibleWithoutEditable
            ->reject(fn (CleaningBookingSession $session): bool => in_array(
                $this->status($session),
                [CleaningBookingSessionStatus::Cancelled->value, CleaningBookingSessionStatus::Skipped->value],
                true,
            ))
            ->sum('total_price'), 2);
        $cancellationFees = round((float) $visibleWithoutEditable
            ->filter(fn (CleaningBookingSession $session): bool => $this->status($session) === CleaningBookingSessionStatus::Cancelled->value)
            ->sum('cancellation_fee'), 2);
        $proposedVisitsTotal = round((float) $singleVisitPricing['totalPrice'] * count($proposedSessions), 2);
        $newGrossTotal = round($immutableChargeableTotal + $proposedVisitsTotal + $cancellationFees, 2);
        $discount = min($newGrossTotal, max(0.0, (float) ($booking->discount_amount ?? 0)));
        $newTotal = round($newGrossTotal - $discount, 2);
        $oldTotal = round((float) $booking->total_price, 2);
        $priceDelta = round($newTotal - $oldTotal, 2);
        $currentFuture = $editable->map(fn (CleaningBookingSession $session): array => [
            'date' => $session->scheduled_date?->toDateString(),
            'time' => (string) $session->scheduled_time,
        ])->all();
        $scheduleChanged = $currentFuture !== $proposedSessions;
        $tokenPayload = [
            'bookingId' => (int) $booking->id,
            'bookingUpdatedAt' => $booking->updated_at?->toIso8601String(),
            'state' => $sessions->map(fn (CleaningBookingSession $session): array => [
                'id' => (int) $session->id,
                'version' => (int) $session->version,
                'status' => $this->status($session),
                'date' => $session->scheduled_date?->toDateString(),
                'time' => (string) $session->scheduled_time,
                'totalPrice' => round((float) $session->total_price, 2),
            ])->all(),
            'proposedSessions' => $proposedSessions,
            'newTotal' => $newTotal,
            'singleVisitTotal' => round((float) $singleVisitPricing['totalPrice'], 2),
        ];
        $revisionToken = hash('sha256', json_encode($tokenPayload, JSON_THROW_ON_ERROR));

        $preview = [
            'revisionToken' => $revisionToken,
            'requiresReconfirmation' => $scheduleChanged,
            'scheduleChanged' => $scheduleChanged,
            'priceChanged' => abs($priceDelta) >= 0.01,
            'oldTotal' => $oldTotal,
            'newTotal' => $newTotal,
            'priceDelta' => $priceDelta,
            'discountAmount' => round($discount, 2),
            'currency' => (string) ($singleVisitPricing['currency'] ?? config('app.currency', 'SYP')),
            'editableSessionsCount' => $editable->count(),
            'preservedSessionsCount' => $visibleWithoutEditable->count(),
            'proposedSessionsCount' => count($proposedSessions),
            'sessionHours' => $sessionHours,
            'singleVisitPricing' => [
                'basePrice' => round((float) $singleVisitPricing['basePrice'], 2),
                'addonsTotal' => round((float) $singleVisitPricing['addonsTotal'], 2),
                'travelFee' => round((float) $singleVisitPricing['travelFee'], 2),
                'adminMargin' => round((float) $singleVisitPricing['adminMargin'], 2),
                'totalPrice' => round((float) $singleVisitPricing['totalPrice'], 2),
                'isPricingFinal' => (bool) $singleVisitPricing['isPricingFinal'],
            ],
            'sessions' => array_map(fn (array $session): array => [
                ...$session,
                'hours' => $sessionHours,
                'totalPrice' => round((float) $singleVisitPricing['totalPrice'], 2),
            ], $proposedSessions),
        ];

        return [
            'preview' => $preview,
            'editable' => $editable,
            'proposedSessions' => $proposedSessions,
            'singleVisitPricing' => $singleVisitPricing,
            'sessionHours' => $sessionHours,
        ];
    }

    /** @return Collection<int,CleaningBookingSession> */
    private function sessions(CleaningBooking $booking, bool $lock): Collection
    {
        $query = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->with('workerAssignments')
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function isEditableFutureSession(CleaningBookingSession $session): bool
    {
        if (! in_array($this->status($session), [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            return false;
        }
        $startsAt = $session->startsAt();
        if ($startsAt === null || ! $startsAt->gt(CarbonImmutable::now(config('app.timezone')))) {
            return false;
        }
        if ($session->started_travel_at !== null || $session->work_started_at !== null) {
            return false;
        }

        return ! $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && $assignment->started_travel_at !== null,
        );
    }

    /** @param array<string,mixed> $schedule @return array<int,array{date:string,time:string}> */
    private function normalizeProposedSessions(array $schedule): array
    {
        $sessions = is_array($schedule['sessions'] ?? null) ? $schedule['sessions'] : [];
        $normalized = [];
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            $normalized[] = [
                'date' => CarbonImmutable::parse((string) ($session['date'] ?? ''), config('app.timezone'))->toDateString(),
                'time' => mb_trim((string) ($session['time'] ?? '')),
            ];
        }
        usort($normalized, static fn (array $left, array $right): int => [$left['date'], $left['time']] <=> [$right['date'], $right['time']]);

        return $normalized;
    }

    private function resequenceVisibleSessions(CleaningBooking $booking): void
    {
        $visible = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($visible->values() as $index => $session) {
            $session->forceFill(['sequence' => 2000000 + $index])->saveQuietly();
        }
        foreach ($visible->values() as $index => $session) {
            $session->forceFill(['sequence' => $index + 1])->saveQuietly();
        }
    }

    private function assertRecurringAndEditable(CleaningBooking $booking): void
    {
        if ($booking->recurring_paused_at !== null) {
            throw ValidationException::withMessages([
                'schedule' => ['استأنف الحجز الدوري قبل تعديل مواعيده المستقبلية.'],
            ]);
        }
        $hasRecurring = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->exists();
        if (! $hasRecurring) {
            throw ValidationException::withMessages([
                'schedule' => ['هذا الطلب ليس حجز تنظيف دوري.'],
            ]);
        }
    }

    private function assertCustomer(CleaningBooking $booking, int $customerId): void
    {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }
    }

    private function status(CleaningBookingSession $session): string
    {
        return $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;
    }
}
'''
Path("Modules/User/app/Services/RecurringCleaningScheduleRevisionService.php").write_text(service)

controller = r'''<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\User\Http\Requests\UserRecurringCleaningScheduleRevisionRequest;
use Modules\User\Services\RecurringCleaningScheduleRevisionService;

final class UserRecurringCleaningScheduleRevisionController
{
    public function preview(
        UserRecurringCleaningScheduleRevisionRequest $request,
        int $order,
        RecurringCleaningScheduleRevisionService $service,
    ): JsonResponse {
        $booking = $this->ownedBooking($request, $order);
        $revision = $service->preview(
            $booking,
            (int) $request->user()->id,
            (array) $request->validated('schedule'),
        );

        return response()->json([
            'success' => true,
            'data' => ['revision' => $revision],
        ]);
    }

    public function confirm(
        UserRecurringCleaningScheduleRevisionRequest $request,
        int $order,
        RecurringCleaningScheduleRevisionService $service,
        CleaningBookingSchedulePresenter $presenter,
    ): JsonResponse {
        $token = mb_trim((string) $request->input('revisionToken'));
        if ($token === '') {
            throw ValidationException::withMessages([
                'revisionToken' => ['معاينة التعديل مطلوبة قبل التأكيد.'],
            ]);
        }

        $booking = $this->ownedBooking($request, $order);
        $result = $service->confirm(
            $booking,
            (int) $request->user()->id,
            (array) $request->validated('schedule'),
            $token,
        );
        $fresh = $result['booking'];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $fresh->id,
                'bookingId' => (int) $fresh->id,
                'bookingNumber' => (string) $fresh->booking_number,
                'status' => $fresh->status?->value ?? (string) $fresh->status,
                'schedule' => $presenter->present($fresh),
                'revision' => $result['revision'],
            ],
        ]);
    }

    private function ownedBooking(UserRecurringCleaningScheduleRevisionRequest $request, int $order): CleaningBooking
    {
        return CleaningBooking::query()
            ->where('customer_id', (int) $request->user()->id)
            ->findOrFail($order);
    }
}
'''
Path("Modules/User/app/Http/Controllers/API/UserRecurringCleaningScheduleRevisionController.php").write_text(controller)

# Add user routes and controller import.
replace_once(
    "Modules/User/routes/api.php",
    "use Modules\\User\\Http\\Controllers\\API\\UserCleaningOrderUpdateController;\n",
    "use Modules\\User\\Http\\Controllers\\API\\UserCleaningOrderUpdateController;\nuse Modules\\User\\Http\\Controllers\\API\\UserRecurringCleaningScheduleRevisionController;\n",
    "revision controller route import",
)
replace_once(
    "Modules/User/routes/api.php",
    "        Route::patch('cleaning/orders/{order}', UserCleaningOrderUpdateController::class);\n",
    "        Route::patch('cleaning/orders/{order}', UserCleaningOrderUpdateController::class);\n        Route::post('cleaning/orders/{order}/recurring-schedule/preview', [UserRecurringCleaningScheduleRevisionController::class, 'preview']);\n        Route::post('cleaning/orders/{order}/recurring-schedule/confirm', [UserRecurringCleaningScheduleRevisionController::class, 'confirm']);\n",
    "revision routes",
)

test = r'''<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Recurring revision cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ],
    );
    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Recurring revision billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ],
    );
});

function recurringRevisionPayload(): array
{
    return [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduledTime' => '10:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 1,
        'schedule' => [
            'mode' => 'recurring',
            'sessions' => [
                ['date' => now(config('app.timezone'))->addDays(2)->toDateString(), 'time' => '10:00'],
                ['date' => now(config('app.timezone'))->addDays(9)->toDateString(), 'time' => '10:00'],
                ['date' => now(config('app.timezone'))->addDays(16)->toDateString(), 'time' => '10:00'],
            ],
        ],
        'termsAccepted' => true,
    ];
}

function createRecurringRevisionBooking(User $customer): CleaningBooking
{
    Sanctum::actingAs($customer);
    $response = postJson('/api/v1/user/cleaning/orders', recurringRevisionPayload())->assertCreated();

    return CleaningBooking::query()->findOrFail((int) $response->json('order.id'));
}

function revisionSchedule(array $days): array
{
    return [
        'mode' => 'recurring',
        'sessions' => array_map(
            static fn (int $day): array => [
                'date' => now(config('app.timezone'))->addDays($day)->toDateString(),
                'time' => '11:00',
            ],
            $days,
        ),
    ];
}

it('previews a recurring future schedule revision without mutating current visits', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $beforeDates = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->pluck('scheduled_date')
        ->map(fn ($date): string => \Carbon\Carbon::parse($date)->toDateString())
        ->all();

    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => revisionSchedule([3, 10, 17])],
    )->assertOk();

    $preview
        ->assertJsonPath('data.revision.requiresReconfirmation', true)
        ->assertJsonPath('data.revision.scheduleChanged', true)
        ->assertJsonPath('data.revision.priceChanged', false)
        ->assertJsonPath('data.revision.editableSessionsCount', 3)
        ->assertJsonPath('data.revision.proposedSessionsCount', 3);
    expect((string) $preview->json('data.revision.revisionToken'))->toHaveLength(64)
        ->and(CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->orderBy('sequence')
            ->pluck('scheduled_date')
            ->map(fn ($date): string => \Carbon\Carbon::parse($date)->toDateString())
            ->all())->toBe($beforeDates);
});

it('confirms an exact preview and keeps superseded visits as hidden audit history', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $schedule = revisionSchedule([3, 10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();
    $token = (string) $preview->json('data.revision.revisionToken');

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        ['schedule' => $schedule, 'revisionToken' => $token],
    )
        ->assertOk()
        ->assertJsonPath('data.revision.applied', true)
        ->assertJsonPath('data.schedule.sessionsCount', 3)
        ->assertJsonPath('data.schedule.sessions.0.date', now(config('app.timezone'))->addDays(3)->toDateString())
        ->assertJsonPath('data.schedule.sessions.2.date', now(config('app.timezone'))->addDays(17)->toDateString());

    expect(CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->where('status', CleaningBookingSessionStatus::Superseded->value)
        ->count())->toBe(3)
        ->and(CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', CleaningBookingSessionStatus::Scheduled->value)
            ->count())->toBe(3);

    getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessionsCount', 3);
});

it('reprices a changed occurrence count and requires confirmation of the new total', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $schedule = revisionSchedule([3, 10, 17, 24]);

    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();

    expect((bool) $preview->json('data.revision.priceChanged'))->toBeTrue()
        ->and((float) $preview->json('data.revision.newTotal'))->toBeGreaterThan((float) $preview->json('data.revision.oldTotal'));

    $token = (string) $preview->json('data.revision.revisionToken');
    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        ['schedule' => $schedule, 'revisionToken' => $token],
    )->assertOk()->assertJsonPath('data.schedule.sessionsCount', 4);

    expect((float) $booking->fresh()->total_price)->toBe((float) $preview->json('data.revision.newTotal'));
});

it('rejects stale recurring revision confirmation tokens', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $schedule = revisionSchedule([3, 10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();
    $token = (string) $preview->json('data.revision.revisionToken');

    $session = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->firstOrFail();
    $session->forceFill(['version' => (int) $session->version + 1])->save();

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        ['schedule' => $schedule, 'revisionToken' => $token],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('revisionToken');
});

it('preserves historical sessions while replacing only editable future visits', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $first = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->firstOrFail();
    $first->forceFill([
        'status' => CleaningBookingSessionStatus::Completed,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subHour(),
    ])->save();
    $firstDate = $first->scheduled_date?->toDateString();

    $schedule = revisionSchedule([10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk()->assertJsonPath('data.revision.preservedSessionsCount', 1);

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        [
            'schedule' => $schedule,
            'revisionToken' => (string) $preview->json('data.revision.revisionToken'),
        ],
    )->assertOk()->assertJsonPath('data.schedule.sessionsCount', 3);

    $preserved = $first->fresh();
    expect($preserved?->status)->toBe(CleaningBookingSessionStatus::Completed)
        ->and($preserved?->scheduled_date?->toDateString())->toBe($firstDate);
});

it('releases accepted future workers when the customer confirms a schedule revision', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $worker = Worker::factory()->create();
    $session = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->firstOrFail();
    $assignment = CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now(),
        'service_share_amount' => 100,
        'travel_fee' => 0,
        'admin_margin_amount' => 10,
        'worker_amount' => 90,
        'currency' => 'SYP',
    ]);
    $session->forceFill(['status' => CleaningBookingSessionStatus::WorkerAssigned])->save();

    $schedule = revisionSchedule([3, 10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();
    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        [
            'schedule' => $schedule,
            'revisionToken' => (string) $preview->json('data.revision.revisionToken'),
        ],
    )->assertOk();

    $assignment->refresh();
    expect($assignment->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($assignment->released_at)->not->toBeNull()
        ->and((string) $assignment->released_reason)->toContain('schedule revision');
});

it('blocks revisions while a recurring series is paused and enforces the thirty day future window', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $booking->forceFill([
        'recurring_paused_at' => now(),
        'recurring_pause_reason' => 'Away',
    ])->save();

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => revisionSchedule([3, 10])],
    )->assertUnprocessable()->assertJsonValidationErrors('schedule');

    $booking->forceFill(['recurring_paused_at' => null, 'recurring_pause_reason' => null])->save();
    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => revisionSchedule([2, 33])],
    )->assertUnprocessable()->assertJsonValidationErrors('schedule.sessions');
});
'''
Path("tests/Feature/UserModule/RecurringCleaningScheduleRevisionTest.php").write_text(test)
