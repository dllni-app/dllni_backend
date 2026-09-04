from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected one match, found {count}")
    return text.replace(old, new, 1)


# Session status supports a reversible paused state. It is intentionally neither
# active nor terminal: paused visits remain part of the series but are not open
# for worker acceptance until resume.
status = Path("Modules/Cleaning/app/Enums/CleaningBookingSessionStatus.php")
text = status.read_text()
text = replace_once(
    text,
    "    case Cancelled = 'cancelled';\n    case Skipped = 'skipped';\n",
    "    case Cancelled = 'cancelled';\n    case Skipped = 'skipped';\n    case Paused = 'paused';\n",
    "session paused enum",
)
text = replace_once(
    text,
    "            self::Skipped => 'متخطاة',\n",
    "            self::Skipped => 'متخطاة',\n            self::Paused => 'متوقفة مؤقتاً',\n",
    "session paused label",
)
status.write_text(text)

# Parent booking stores series-level pause metadata. Session status stores the
# actual visit availability, so no duplicate per-session pause timestamp is needed.
model = Path("Modules/Cleaning/app/Models/CleaningBooking.php")
text = model.read_text()
text = replace_once(
    text,
    "        'customer_confirmed_at',\n        'cancelled_at',\n",
    "        'customer_confirmed_at',\n        'recurring_paused_at',\n        'recurring_pause_reason',\n        'cancelled_at',\n",
    "booking pause fillable",
)
text = replace_once(
    text,
    "            'customer_confirmed_at' => 'datetime',\n            'address_latitude' => 'decimal:8',\n",
    "            'customer_confirmed_at' => 'datetime',\n            'recurring_paused_at' => 'datetime',\n            'address_latitude' => 'decimal:8',\n",
    "booking pause cast",
)
model.write_text(text)

migration = Path("database/migrations/2026_09_05_000010_add_recurring_pause_fields_to_cleaning_bookings_table.php")
migration.write_text(r'''<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->timestamp('recurring_paused_at')->nullable()->after('customer_confirmed_at');
            $table->text('recurring_pause_reason')->nullable()->after('recurring_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn(['recurring_paused_at', 'recurring_pause_reason']);
        });
    }
};
''')

# Move parent financial aggregation out of cancellation so pause/resume can use
# the exact same source of truth when a visit expires during a pause.
aggregation = Path("Modules/Cleaning/app/Services/CleaningBookingSessionFinancialAggregationService.php")
aggregation.write_text(r'''<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingSessionFinancialAggregationService
{
    public function sync(CleaningBooking $booking): void
    {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->get();

        $chargeable = $sessions->reject(function (CleaningBookingSession $session): bool {
            return in_array($this->statusValue($session), [
                CleaningBookingSessionStatus::Cancelled->value,
                CleaningBookingSessionStatus::Skipped->value,
            ], true);
        });
        $cancelled = $sessions->filter(
            fn (CleaningBookingSession $session): bool => $this->statusValue($session) === CleaningBookingSessionStatus::Cancelled->value,
        );

        $basePrice = round((float) $chargeable->sum('base_price'), 2);
        $addonsTotal = round((float) $chargeable->sum('addons_total'), 2);
        $travelFee = round((float) $chargeable->sum('travel_fee'), 2);
        $adminMargin = round((float) $chargeable->sum('admin_margin_amount'), 2);
        $extensionFee = round((float) $chargeable->sum('extension_fee_total'), 2);
        $cancellationFee = round((float) $cancelled->sum('cancellation_fee'), 2);
        $serviceTotal = round((float) $chargeable->sum('total_price'), 2);
        $totalHours = round((float) $chargeable->sum('duration_hours'), 2);

        CleaningBooking::query()
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail()
            ->forceFill([
                'base_price' => $basePrice,
                'addons_total' => $addonsTotal,
                'travel_fee' => $travelFee,
                'admin_margin_amount' => $adminMargin,
                'extension_fee_total' => $extensionFee,
                'cancellation_fee' => $cancellationFee,
                'total_hours' => $totalHours,
                'total_price' => round($serviceTotal + $cancellationFee, 2),
            ])->saveQuietly();
    }

    private function statusValue(CleaningBookingSession $session): string
    {
        return $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;
    }
}
''')

cancellation = Path("Modules/Cleaning/app/Services/CleaningBookingSessionCancellationService.php")
text = cancellation.read_text()
text = replace_once(
    text,
    "        private readonly CleaningBookingSessionParentStateService $parentState,\n    ) {}\n",
    "        private readonly CleaningBookingSessionParentStateService $parentState,\n        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,\n    ) {}\n",
    "cancellation aggregation injection",
)
text = text.replace("            $this->syncParentFinancials($booking);", "            $this->financialAggregation->sync($booking);")
start = text.find("    private function syncParentFinancials(CleaningBooking $booking): void\n")
end = text.find("    private function lockSession(\n", start)
if start == -1 or end == -1:
    raise SystemExit("cancellation financial aggregation block not found")
text = text[:start] + text[end:]
cancellation.write_text(text)

pause_service = Path("Modules/Cleaning/app/Services/RecurringCleaningPauseService.php")
pause_service.write_text(r'''<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class RecurringCleaningPauseService
{
    public function __construct(
        private readonly CleaningLifecycleNotificationService $notifications,
        private readonly CleaningBookingSessionParentStateService $parentState,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
    ) {}

    /** @return array{booking:CleaningBooking,pausedSessionIds:array<int,int>,releasedWorkerIds:array<int,int>} */
    public function pause(CleaningBooking $booking, int $customerId, string $reason): array
    {
        $this->assertCustomer($booking, $customerId);
        $normalizedReason = $this->requiredReason($reason);
        $pausedSessionIds = [];
        $releasedWorkerIds = [];

        DB::transaction(function () use (
            $booking,
            $normalizedReason,
            &$pausedSessionIds,
            &$releasedWorkerIds,
        ): void {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertRecurring($lockedBooking);
            if ($lockedBooking->recurring_paused_at !== null) {
                throw new InvalidArgumentException('Recurring cleaning series is already paused.');
            }

            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
                ->whereIn('status', [
                    CleaningBookingSessionStatus::Scheduled->value,
                    CleaningBookingSessionStatus::WorkerAssigned->value,
                ])
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $startsAt = $session->startsAt();
                if (
                    $startsAt === null
                    || ! $startsAt->isFuture()
                    || $session->started_travel_at !== null
                    || $session->work_started_at !== null
                ) {
                    continue;
                }

                $assignments = CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->lockForUpdate()
                    ->get();

                if ($assignments->contains(
                    static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->started_travel_at !== null,
                )) {
                    continue;
                }

                $pausedAt = now();
                foreach ($assignments as $assignment) {
                    $workerId = (int) $assignment->worker_id;
                    if ($workerId > 0) {
                        $releasedWorkerIds[] = $workerId;
                    }
                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                        'released_at' => $pausedAt,
                        'released_reason' => 'Customer paused recurring cleaning series: '.$normalizedReason,
                    ])->save();
                }

                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::Paused,
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'version' => max(1, (int) $session->version) + 1,
                ])->save();
                $pausedSessionIds[] = (int) $session->id;
            }

            if ($pausedSessionIds === []) {
                throw new InvalidArgumentException('No future recurring visits are eligible to pause.');
            }

            $lockedBooking->forceFill([
                'recurring_paused_at' => now(),
                'recurring_pause_reason' => $normalizedReason,
            ])->save();
        }, 3);

        $this->parentState->refresh($booking);
        $freshBooking = $booking->fresh(['customer']) ?? $booking;
        $releasedWorkerIds = array_values(array_unique($releasedWorkerIds));

        foreach ($releasedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $freshBooking,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: 'customer_paused_recurring_series',
                actorRole: 'customer',
                occurredAt: $freshBooking->recurring_paused_at?->toIso8601String(),
                extraData: [
                    'pausedSessionIds' => $pausedSessionIds,
                    'pauseReason' => $normalizedReason,
                ],
            );
        }

        return [
            'booking' => $freshBooking,
            'pausedSessionIds' => $pausedSessionIds,
            'releasedWorkerIds' => $releasedWorkerIds,
        ];
    }

    /** @return array{booking:CleaningBooking,resumedSessionIds:array<int,int>,expiredSessionIds:array<int,int>} */
    public function resume(CleaningBooking $booking, int $customerId): array
    {
        $this->assertCustomer($booking, $customerId);
        $resumedSessionIds = [];
        $expiredSessionIds = [];

        DB::transaction(function () use (
            $booking,
            &$resumedSessionIds,
            &$expiredSessionIds,
        ): void {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertRecurring($lockedBooking);
            if ($lockedBooking->recurring_paused_at === null) {
                throw new InvalidArgumentException('Recurring cleaning series is not paused.');
            }

            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
                ->where('status', CleaningBookingSessionStatus::Paused->value)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if ($sessions->isEmpty()) {
                throw new InvalidArgumentException('Recurring cleaning series has no paused visits to resume.');
            }

            foreach ($sessions as $session) {
                $startsAt = $session->startsAt();
                if ($startsAt === null || ! $startsAt->isFuture()) {
                    $session->forceFill([
                        'status' => CleaningBookingSessionStatus::Skipped,
                        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                        'skipped_at' => now(),
                        'skip_source' => 'recurring_pause_expired',
                        'skip_reason' => 'Visit time passed while recurring cleaning series was paused.',
                        'cancellation_fee' => 0,
                        'version' => max(1, (int) $session->version) + 1,
                    ])->save();
                    $expiredSessionIds[] = (int) $session->id;
                    continue;
                }

                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::Scheduled,
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'version' => max(1, (int) $session->version) + 1,
                ])->save();
                $resumedSessionIds[] = (int) $session->id;
            }

            $lockedBooking->forceFill([
                'recurring_paused_at' => null,
                'recurring_pause_reason' => null,
            ])->save();

            if ($expiredSessionIds !== []) {
                $this->financialAggregation->sync($lockedBooking);
            }
        }, 3);

        $this->parentState->refresh($booking);

        return [
            'booking' => $booking->fresh(['customer']) ?? $booking,
            'resumedSessionIds' => $resumedSessionIds,
            'expiredSessionIds' => $expiredSessionIds,
        ];
    }

    private function assertCustomer(CleaningBooking $booking, int $customerId): void
    {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }
    }

    private function assertRecurring(CleaningBooking $booking): void
    {
        $hasRecurringSessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
            ->exists();

        if (! $hasRecurringSessions) {
            throw new InvalidArgumentException('Only recurring cleaning bookings can be paused or resumed.');
        }
    }

    private function requiredReason(string $reason): string
    {
        $normalized = mb_trim($reason);
        if ($normalized === '') {
            throw new InvalidArgumentException('Pause reason is required.');
        }

        return mb_substr($normalized, 0, 1000);
    }
}
''')

controller = Path("Modules/Cleaning/app/Http/Controllers/API/CleaningRecurringSeriesController.php")
controller.write_text(r'''<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\RecurringCleaningPauseService;

final class CleaningRecurringSeriesController
{
    public function __construct(
        private readonly RecurringCleaningPauseService $pauseService,
        private readonly CleaningBookingSchedulePresenter $presenter,
    ) {}

    public function pause(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->pauseService->pause(
                $cleaning_booking,
                (int) $request->user()->id,
                (string) $validated['reason'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($result['booking'], [
            'action' => 'paused',
            'pausedSessionIds' => $result['pausedSessionIds'],
            'releasedWorkerIds' => $result['releasedWorkerIds'],
        ]);
    }

    public function resume(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        try {
            $result = $this->pauseService->resume(
                $cleaning_booking,
                (int) $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return $this->payload($result['booking'], [
            'action' => 'resumed',
            'resumedSessionIds' => $result['resumedSessionIds'],
            'expiredSessionIds' => $result['expiredSessionIds'],
        ]);
    }

    /** @param array<string, mixed> $seriesAction */
    private function payload(CleaningBooking $booking, array $seriesAction): JsonResponse
    {
        $fresh = $booking->fresh() ?? $booking;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $fresh->id,
                'bookingId' => (int) $fresh->id,
                'bookingNumber' => (string) $fresh->booking_number,
                'status' => $fresh->status?->value ?? (string) $fresh->status,
                'totalPrice' => (float) $fresh->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                'schedule' => $this->presenter->present($fresh),
                'seriesAction' => $seriesAction,
            ],
        ]);
    }
}
''')

# Routes for series-level pause/resume.
routes = Path("Modules/Cleaning/routes/sessions.php")
text = routes.read_text()
text = replace_once(
    text,
    "use Modules\\Cleaning\\Http\\Controllers\\API\\CleaningBookingSessionLocationController;\n",
    "use Modules\\Cleaning\\Http\\Controllers\\API\\CleaningBookingSessionLocationController;\nuse Modules\\Cleaning\\Http\\Controllers\\API\\CleaningRecurringSeriesController;\n",
    "recurring controller import",
)
marker = "        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/accept-all',\n"
series_routes = r'''        Route::post(
            'cleaning-bookings/{cleaning_booking}/recurring/pause',
            [CleaningRecurringSeriesController::class, 'pause'],
        )->name('cleaning-bookings.recurring.pause');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/recurring/resume',
            [CleaningRecurringSeriesController::class, 'resume'],
        )->name('cleaning-bookings.recurring.resume');

'''
text = replace_once(text, marker, series_routes + marker, "recurring series routes")
routes.write_text(text)

# Acceptance must never treat paused visits as open seats.
acceptance = Path("Modules/Cleaning/app/Services/CleaningBookingSessionAcceptanceService.php")
text = acceptance.read_text()
text = replace_once(
    text,
    "                ->whereNotIn('status', CleaningBookingSessionStatus::terminalValues())\n                ->orderBy('sequence')\n",
    "                ->whereIn('status', [\n                    CleaningBookingSessionStatus::Scheduled->value,\n                    CleaningBookingSessionStatus::WorkerAssigned->value,\n                ])\n                ->orderBy('sequence')\n",
    "accept all open statuses",
)
old = '''        if ($session->isTerminal()) {
            return $this->rejection((int) $session->id, 'session_closed', 'This session is no longer open for acceptance.');
        }

        if ($session->remainingWorkerCount() <= 0) {
'''
new = '''        $status = $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;

        if ($status === CleaningBookingSessionStatus::Paused->value) {
            return $this->rejection((int) $session->id, 'session_paused', 'This recurring visit is paused and cannot be accepted.');
        }

        if ($session->isTerminal()) {
            return $this->rejection((int) $session->id, 'session_closed', 'This session is no longer open for acceptance.');
        }

        if (! in_array($status, [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            return $this->rejection((int) $session->id, 'session_not_open', 'This session is not open for worker acceptance.');
        }

        if ($session->remainingWorkerCount() <= 0) {
'''
text = replace_once(text, old, new, "accept paused rejection")
acceptance.write_text(text)

# Presenter exposes series state and keeps paused sessions out of next actionable visit.
presenter = Path("Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php")
text = presenter.read_text()
text = replace_once(
    text,
    "        $next = $sessions\n            ->filter(fn (CleaningBookingSession $session): bool => ! $session->isTerminal())\n",
    "        $next = $sessions\n            ->filter(fn (CleaningBookingSession $session): bool => ! $session->isTerminal()\n                && $this->status($session) !== CleaningBookingSessionStatus::Paused->value)\n",
    "presenter next excludes paused",
)
text = replace_once(
    text,
    "        $canReschedule = $this->eventScheduleCanReschedule($booking, $sessions, $viewerWorker);\n\n        return [\n",
    "        $canReschedule = $this->eventScheduleCanReschedule($booking, $sessions, $viewerWorker);\n        $isRecurring = $sessions->contains(\n            fn (CleaningBookingSession $session): bool => (string) $session->session_type === CleaningBookingSession::TYPE_RECURRING_CLEANING,\n        );\n        $isRecurringPaused = $isRecurring && $booking->recurring_paused_at !== null;\n        $isCustomerView = ! $viewerWorker instanceof Worker;\n        $canPauseRecurring = $isCustomerView\n            && $isRecurring\n            && ! $isRecurringPaused\n            && $sessions->contains(fn (CleaningBookingSession $session): bool => $this->canPauseRecurringSession($session));\n        $canResumeRecurring = $isCustomerView\n            && $isRecurringPaused\n            && $sessions->contains(\n                fn (CleaningBookingSession $session): bool => $this->status($session) === CleaningBookingSessionStatus::Paused->value,\n            );\n\n        return [\n",
    "presenter recurring series state calculation",
)
text = replace_once(
    text,
    "            'mode' => $sessions->count() > 1 ? 'multi_day' : 'single_day',\n            'isMultiSession' => $sessions->count() > 1,\n",
    "            'mode' => $sessions->count() > 1 ? 'multi_day' : 'single_day',\n            'isRecurring' => $isRecurring,\n            'isPaused' => $isRecurringPaused,\n            'canPause' => $canPauseRecurring,\n            'canResume' => $canResumeRecurring,\n            'pausedAt' => $booking->recurring_paused_at?->toIso8601String(),\n            'pauseReason' => $booking->recurring_pause_reason,\n            'isMultiSession' => $sessions->count() > 1,\n",
    "presenter recurring root payload",
)
text = replace_once(
    text,
    "        $canSendSos = ! $session->isTerminal()\n            && ($isCustomerView || $hasMyActiveAssignment);\n",
    "        $canSendSos = ! $session->isTerminal()\n            && $status !== CleaningBookingSessionStatus::Paused->value\n            && ($isCustomerView || $hasMyActiveAssignment);\n",
    "presenter paused sos",
)
marker = "    /** @return array<string, mixed> */\n    private function assignmentPayload("
helper = r'''    private function canPauseRecurringSession(CleaningBookingSession $session): bool
    {
        if ((string) $session->session_type !== CleaningBookingSession::TYPE_RECURRING_CLEANING) {
            return false;
        }

        if (! in_array($this->status($session), [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            return false;
        }

        $startsAt = $session->startsAt();
        if (
            $startsAt === null
            || ! $startsAt->isFuture()
            || $session->started_travel_at !== null
            || $session->work_started_at !== null
        ) {
            return false;
        }

        return ! $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && $assignment->started_travel_at !== null,
        );
    }

'''
text = replace_once(text, marker, helper + marker, "presenter pause helper")
text = replace_once(
    text,
    "            'mode' => 'single_day',\n            'isMultiSession' => false,\n",
    "            'mode' => 'single_day',\n            'isRecurring' => false,\n            'isPaused' => false,\n            'canPause' => false,\n            'canResume' => false,\n            'pausedAt' => null,\n            'pauseReason' => null,\n            'isMultiSession' => false,\n",
    "single day recurring fields",
)
presenter.write_text(text)

# Regression coverage piggybacks on existing recurring worker continuity fixtures.
test = Path("tests/Feature/Cleaning/RecurringCleaningWorkerContinuityTest.php")
text = test.read_text()
marker = "/** @return array{0:User,1:User,2:Worker,3:CleaningBooking} */\nfunction makeRecurringWorkerContinuityScenario(): array\n"
cases = r'''it('pauses future recurring visits, releases workers and blocks acceptance until resume', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    $firstAssignment = makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    $secondAssignment = makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.isRecurring', true)
        ->assertJsonPath('data.schedule.isPaused', false)
        ->assertJsonPath('data.schedule.canPause', true)
        ->assertJsonPath('data.schedule.canResume', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'إيقاف الزيارات لأسبوعين'],
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.isPaused', true)
        ->assertJsonPath('data.schedule.canPause', false)
        ->assertJsonPath('data.schedule.canResume', true)
        ->assertJsonPath('data.schedule.pauseReason', 'إيقاف الزيارات لأسبوعين')
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Paused->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Paused->value);

    expect($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($secondAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($firstSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Paused)
        ->and($secondSession->fresh()->status)->toBe(CleaningBookingSessionStatus::Paused)
        ->and($booking->fresh()->recurring_paused_at)->not->toBeNull()
        ->and($booking->fresh()->recurring_pause_reason)->toBe('إيقاف الزيارات لأسبوعين');

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        ['sessionIds' => [$firstSession->id]],
    )
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.acceptance.rejected.0.reasonCode', 'session_paused');
});

it('resumes paused future visits and makes them available for acceptance again', function (): void {
    [$customer, $workerUser, $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'توقف مؤقت'],
    )->assertOk();

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/resume",
    )
        ->assertOk()
        ->assertJsonPath('data.schedule.isPaused', false)
        ->assertJsonPath('data.schedule.canPause', true)
        ->assertJsonPath('data.schedule.canResume', false)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Scheduled->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Scheduled->value);

    expect($booking->fresh()->recurring_paused_at)->toBeNull()
        ->and($booking->fresh()->recurring_pause_reason)->toBeNull();

    Sanctum::actingAs($workerUser);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        ['sessionIds' => [$firstSession->id]],
    )
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.acceptance.acceptedSessionIds.0', $firstSession->id);
});

it('turns visits that expired during a pause into penalty-free skipped visits on resume', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $firstSession = makeRecurringWorkerContinuitySession($booking, 1);
    $secondSession = makeRecurringWorkerContinuitySession($booking, 2);
    makeRecurringWorkerContinuityAssignment($firstSession, $worker);
    makeRecurringWorkerContinuityAssignment($secondSession, $worker);

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'توقف مؤقت'],
    )->assertOk();

    $firstSession->forceFill([
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => now()->subHour()->format('H:i'),
    ])->save();

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/resume",
    )
        ->assertOk()
        ->assertJsonPath('data.seriesAction.expiredSessionIds.0', $firstSession->id)
        ->assertJsonPath('data.schedule.sessions.0.status', CleaningBookingSessionStatus::Skipped->value)
        ->assertJsonPath('data.schedule.sessions.1.status', CleaningBookingSessionStatus::Scheduled->value);

    expect($firstSession->fresh()->skip_source)->toBe('recurring_pause_expired')
        ->and((float) $firstSession->fresh()->cancellation_fee)->toBe(0.0)
        ->and((float) $booking->fresh()->total_hours)->toBe(2.0)
        ->and((float) $booking->fresh()->total_price)->toBe(3300.0);

    $this->assertDatabaseMissing('cleaning_booking_session_financial_penalties', [
        'cleaning_booking_session_id' => $firstSession->id,
    ]);
});

it('rejects pausing an ordinary non-recurring cleaning booking', function (): void {
    [$customer, , $worker, $booking] = makeRecurringWorkerContinuityScenario();
    $session = makeRecurringWorkerContinuitySession($booking, 1, 'regular_cleaning');
    $assignment = makeRecurringWorkerContinuityAssignment($session, $worker);

    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/recurring/pause",
        ['reason' => 'محاولة غير صالحة'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::WorkerAssigned)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart);
});

'''
text = replace_once(text, marker, cases + marker, "pause resume regression cases")
test.write_text(text)
