from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise SystemExit(f"missing patch anchor in {path}: {old[:120]!r}")
    file.write_text(text.replace(old, new, 1))


def write(path: str, content: str) -> None:
    file = Path(path)
    file.parent.mkdir(parents=True, exist_ok=True)
    file.write_text(content)


# 1) Session interaction persistence.
write(
    "Modules/Cleaning/database/migrations/2026_09_06_000030_add_interaction_state_to_cleaning_booking_sessions.php",
    r'''<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->string('payment_status')->default('pending')->after('is_pricing_final');
            $table->timestamp('payment_settled_at')->nullable()->after('payment_status');
            $table->index(['payment_status', 'scheduled_date'], 'cleaning_session_payment_status_idx');
        });

        Schema::table('worker_customer_ratings', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_session_id')
                ->nullable()
                ->after('booking_type')
                ->constrained('cleaning_booking_sessions')
                ->nullOnDelete();
            $table->dropUnique('wcr_booking_worker_customer_rating_unique');
            $table->unique(
                [
                    'booking_id',
                    'booking_type',
                    'cleaning_booking_session_id',
                    'worker_id',
                    'customer_id',
                    'rating_type',
                ],
                'wcr_booking_session_worker_rating_unique',
            );
        });

        Schema::table('disputes', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_session_id')
                ->nullable()
                ->after('booking_type')
                ->constrained('cleaning_booking_sessions')
                ->nullOnDelete();
            $table->index(
                ['cleaning_booking_session_id', 'status'],
                'disputes_cleaning_session_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table): void {
            $table->dropIndex('disputes_cleaning_session_status_idx');
            $table->dropConstrainedForeignId('cleaning_booking_session_id');
        });

        Schema::table('worker_customer_ratings', function (Blueprint $table): void {
            $table->dropUnique('wcr_booking_session_worker_rating_unique');
            $table->dropConstrainedForeignId('cleaning_booking_session_id');
            $table->unique(
                ['booking_id', 'booking_type', 'worker_id', 'customer_id', 'rating_type'],
                'wcr_booking_worker_customer_rating_unique',
            );
        });

        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->dropIndex('cleaning_session_payment_status_idx');
            $table->dropColumn(['payment_status', 'payment_settled_at']);
        });
    }
};
''',
)

# 2) Session model interaction relationships/state.
path = "Modules/Cleaning/app/Models/CleaningBookingSession.php"
replace_once(
    path,
    "namespace Modules\\Cleaning\\Models;\n\nuse Carbon\\CarbonImmutable;",
    "namespace Modules\\Cleaning\\Models;\n\nuse App\\Models\\Dispute;\nuse App\\Models\\WorkerCustomerRating;\nuse Carbon\\CarbonImmutable;",
)
replace_once(
    path,
    "        'is_pricing_final',\n        'pricing_snapshot',",
    "        'is_pricing_final',\n        'payment_status',\n        'payment_settled_at',\n        'pricing_snapshot',",
)
replace_once(
    path,
    "    public function activeWorkerAssignments(): HasMany\n    {",
    "    public function ratings(): HasMany\n    {\n        return $this->hasMany(WorkerCustomerRating::class, 'cleaning_booking_session_id');\n    }\n\n    public function disputes(): HasMany\n    {\n        return $this->hasMany(Dispute::class, 'cleaning_booking_session_id');\n    }\n\n    public function activeWorkerAssignments(): HasMany\n    {",
)
replace_once(
    path,
    "            'is_pricing_final' => 'boolean',\n            'pricing_snapshot' => 'array',",
    "            'is_pricing_final' => 'boolean',\n            'payment_settled_at' => 'datetime',\n            'pricing_snapshot' => 'array',",
)

# 3) Rating model keeps parent booking context and adds optional exact session context.
path = "app/Models/WorkerCustomerRating.php"
replace_once(
    path,
    "use Illuminate\\Database\\Eloquent\\Relations\\MorphTo;",
    "use Illuminate\\Database\\Eloquent\\Relations\\MorphTo;\nuse Modules\\Cleaning\\Models\\CleaningBookingSession;",
)
replace_once(
    path,
    "        'booking_type',\n        'worker_id',",
    "        'booking_type',\n        'cleaning_booking_session_id',\n        'worker_id',",
)
replace_once(
    path,
    "    public function worker(): BelongsTo\n    {",
    "    public function session(): BelongsTo\n    {\n        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');\n    }\n\n    public function worker(): BelongsTo\n    {",
)

# 4) Disputes can point to one child session while retaining the existing booking morph.
path = "app/Models/Dispute.php"
replace_once(
    path,
    "use Modules\\Delivery\\Models\\DeliveryDriverTrustLog;",
    "use Modules\\Cleaning\\Models\\CleaningBookingSession;\nuse Modules\\Delivery\\Models\\DeliveryDriverTrustLog;",
)
replace_once(
    path,
    "        'booking_type',\n        'ticket_number',",
    "        'booking_type',\n        'cleaning_booking_session_id',\n        'ticket_number',",
)
replace_once(
    path,
    "    public function messages(): HasMany\n    {",
    "    public function session(): BelongsTo\n    {\n        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');\n    }\n\n    public function messages(): HasMany\n    {",
)

path = "app/Data/DisputeData.php"
replace_once(
    path,
    "        public ?string $bookingType,\n        public ?string $ticketNumber,",
    "        public ?string $bookingType,\n        public ?int $cleaningBookingSessionId,\n        public ?string $ticketNumber,",
)

path = "app/Http/Requests/DisputeRequest.php"
replace_once(
    path,
    "            'bookingType' => ['required', 'string', Rule::in(['cleaning_booking', 'event_booking'])],\n            'ticketNumber' => [",
    "            'bookingType' => ['required', 'string', Rule::in(['cleaning_booking', 'event_booking'])],\n            'cleaningBookingSessionId' => ['nullable', 'integer', 'exists:cleaning_booking_sessions,id'],\n            'ticketNumber' => [",
)

path = "app/Http/Resources/DisputeResource.php"
replace_once(
    path,
    "            'bookingType' => $this->booking_type,\n            'ticketNumber' => $this->ticket_number,",
    "            'bookingType' => $this->booking_type,\n            'cleaningBookingSessionId' => $this->cleaning_booking_session_id,\n            'sessionId' => $this->cleaning_booking_session_id,\n            'ticketNumber' => $this->ticket_number,",
)

# Generic disputes endpoint must not accept a session belonging to another booking.
path = "app/Http/Controllers/API/DisputeController.php"
replace_once(
    path,
    "use Modules\\Cleaning\\Models\\CleaningBooking;",
    "use Modules\\Cleaning\\Enums\\CleaningBookingSessionStatus;\nuse Modules\\Cleaning\\Models\\CleaningBooking;\nuse Modules\\Cleaning\\Models\\CleaningBookingSession;",
)
replace_once(
    path,
    "        if (! $this->isAdmin($user)) {\n            abort_unless($data['bookingType'] === 'cleaning_booking', Response::HTTP_FORBIDDEN);\n\n            $booking = CleaningBooking::query()->findOrFail((int) $data['bookingId']);\n            abort_unless((int) $booking->customer_id === (int) $user->id, Response::HTTP_FORBIDDEN);\n        }\n\n        $data['ticketNumber'] ??=",
    "        $booking = null;\n        if (! $this->isAdmin($user)) {\n            abort_unless($data['bookingType'] === 'cleaning_booking', Response::HTTP_FORBIDDEN);\n\n            $booking = CleaningBooking::query()->findOrFail((int) $data['bookingId']);\n            abort_unless((int) $booking->customer_id === (int) $user->id, Response::HTTP_FORBIDDEN);\n        } elseif (($data['bookingType'] ?? null) === 'cleaning_booking') {\n            $booking = CleaningBooking::query()->findOrFail((int) $data['bookingId']);\n        }\n\n        $sessionId = (int) ($data['cleaningBookingSessionId'] ?? 0);\n        if ($sessionId > 0) {\n            abort_unless($booking instanceof CleaningBooking, Response::HTTP_UNPROCESSABLE_ENTITY, 'A cleaning session requires a cleaning booking.');\n\n            $session = CleaningBookingSession::query()\n                ->whereKey($sessionId)\n                ->where('cleaning_booking_id', $booking->id)\n                ->first();\n            abort_unless($session instanceof CleaningBookingSession, Response::HTTP_UNPROCESSABLE_ENTITY, 'Session does not belong to this booking.');\n\n            if (! $this->isAdmin($user)) {\n                $status = $session->status instanceof CleaningBookingSessionStatus\n                    ? $session->status\n                    : CleaningBookingSessionStatus::tryFrom((string) $session->status);\n                abort_unless(\n                    in_array($status, [CleaningBookingSessionStatus::Completed, CleaningBookingSessionStatus::Cancelled], true),\n                    Response::HTTP_UNPROCESSABLE_ENTITY,\n                    'A session dispute can only be opened after completion or cancellation.',\n                );\n            }\n        }\n\n        $data['ticketNumber'] ??=",
)

# 5) Internal financial settlement: preserve legacy booking method and add session-scoped idempotency.
path = "Modules/Cleaning/app/Services/DepositService.php"
replace_once(
    path,
    "use Modules\\Cleaning\\Models\\CleaningBooking;\nuse Modules\\Cleaning\\Models\\CleaningBookingWorkerAssignment;",
    "use Modules\\Cleaning\\Models\\CleaningBooking;\nuse Modules\\Cleaning\\Models\\CleaningBookingSession;\nuse Modules\\Cleaning\\Models\\CleaningBookingWorkerAssignment;",
)
anchor = """    public function resolveLimits(Worker $worker): array\n    {"""
method = """    public function recordSessionAdminFeeDebit(\n        Worker $worker,\n        CleaningBooking $booking,\n        CleaningBookingSession $session,\n        float $amount,\n        ?int $createdByAdminId = null,\n    ): ?CleaningDepositTransaction {\n        if ($amount <= 0) {\n            return null;\n        }\n\n        if ((int) $session->cleaning_booking_id !== (int) $booking->id) {\n            throw new InvalidArgumentException('Session does not belong to this booking.');\n        }\n\n        $reference = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.hash(\n            'sha256',\n            $worker->id.':'.$booking->id.':session:'.$session->id,\n        );\n        if (CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('reference', $reference)->exists()) {\n            return null;\n        }\n\n        return $this->recordCharge($worker, $amount, 'commission', $reference, null, $createdByAdminId);\n    }\n\n"""
replace_once(path, anchor, method + anchor)

# Parent completion observer remains legacy-only. Multi-session bookings settle each session independently.
path = "Modules/Cleaning/app/Observers/CleaningBookingObserver.php"
replace_once(
    path,
    "    private function chargeAdminCommission(CleaningBooking $booking): void\n    {\n        try {\n            $depositService = app(DepositService::class);",
    "    private function chargeAdminCommission(CleaningBooking $booking): void\n    {\n        try {\n            if ($booking->sessions()->exists()) {\n                return;\n            }\n\n            $depositService = app(DepositService::class);",
)

# 6) Session lifecycle exposes pending -> ready -> settled internal payment state.
path = "Modules/Cleaning/app/Services/CleaningBookingSessionLifecycleService.php"
replace_once(
    path,
    "    private const MAX_SECURITY_CODE_ATTEMPTS = 5;\n\n    public function startTravel(",
    "    private const MAX_SECURITY_CODE_ATTEMPTS = 5;\n\n    public function __construct(\n        private readonly DepositService $depositService,\n    ) {}\n\n    public function startTravel(",
)
replace_once(
    path,
    "            $locked->forceFill([\n                'status' => $readyCount >= $locked->requiredWorkerCount()\n                    ? CleaningBookingSessionStatus::AwaitingCustomerCompletion\n                    : CleaningBookingSessionStatus::InProgress,\n                'work_finished_at' => $readyCount >= $locked->requiredWorkerCount()\n                    ? ($locked->work_finished_at ?? now())\n                    : null,\n            ])->save();",
    "            $readyForCustomer = $readyCount >= $locked->requiredWorkerCount();\n            $locked->forceFill([\n                'status' => $readyForCustomer\n                    ? CleaningBookingSessionStatus::AwaitingCustomerCompletion\n                    : CleaningBookingSessionStatus::InProgress,\n                'work_finished_at' => $readyForCustomer\n                    ? ($locked->work_finished_at ?? now())\n                    : null,\n                'payment_status' => $readyForCustomer ? 'ready' : ($locked->payment_status ?: 'pending'),\n            ])->save();",
)
replace_once(
    path,
    "            $locked->forceFill([\n                'status' => CleaningBookingSessionStatus::Completed,\n                'work_finished_at' => $locked->work_finished_at ?? $completedAt,\n                'customer_completed_at' => $locked->customer_completed_at ?? $completedAt,\n            ])->save();\n\n            $this->syncParentStatus($booking);",
    "            $assignments = CleaningBookingSessionWorkerAssignment::query()\n                ->with('worker.deposit')\n                ->where('cleaning_booking_session_id', $locked->id)\n                ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)\n                ->get();\n\n            foreach ($assignments as $assignment) {\n                $worker = $assignment->worker;\n                $adminFee = (float) $assignment->admin_margin_amount;\n                if ($worker instanceof Worker && $adminFee > 0) {\n                    $this->depositService->recordSessionAdminFeeDebit(\n                        $worker,\n                        $booking,\n                        $locked,\n                        $adminFee,\n                    );\n                }\n            }\n\n            $locked->forceFill([\n                'status' => CleaningBookingSessionStatus::Completed,\n                'work_finished_at' => $locked->work_finished_at ?? $completedAt,\n                'customer_completed_at' => $locked->customer_completed_at ?? $completedAt,\n                'payment_status' => 'settled',\n                'payment_settled_at' => $locked->payment_settled_at ?? $completedAt,\n            ])->save();\n\n            $this->syncParentStatus($booking);",
)

# 7) Dedicated generic session interaction service/controller.
write(
    "Modules/Cleaning/app/Services/CleaningBookingSessionInteractionService.php",
    r'''<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\WorkerCustomerRatingType;
use App\Models\Dispute;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Illuminate\Support\Str;

final class CleaningBookingSessionInteractionService
{
    /** @param array{workerId:int,rating:int,comment?:string|null} $validated */
    public function submitReview(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        array $validated,
    ): WorkerCustomerRating {
        $this->assertCustomerSession($booking, $session, $customerId);

        if ($this->sessionStatus($session) !== CleaningBookingSessionStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['A session can only be reviewed after customer-confirmed completion.'],
            ]);
        }

        $workerId = (int) $validated['workerId'];
        $completedAssignment = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $session->id)
            ->where('worker_id', $workerId)
            ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
            ->exists();

        if (! $completedAssignment) {
            throw ValidationException::withMessages([
                'workerId' => ['This worker did not complete this session.'],
            ]);
        }

        $review = WorkerCustomerRating::query()->updateOrCreate(
            [
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'cleaning_booking_session_id' => $session->id,
                'worker_id' => $workerId,
                'customer_id' => $booking->customer_id,
                'rating_type' => WorkerCustomerRatingType::CustomerToWorker->value,
            ],
            [
                'rating' => (int) $validated['rating'],
                'comment' => $this->nullableTrimmed($validated['comment'] ?? null),
            ],
        );

        $this->syncWorkerAverageRating($workerId);

        return $review;
    }

    public function openDispute(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        string $description,
        DisputeCategory $category,
    ): Dispute {
        $this->assertCustomerSession($booking, $session, $customerId);

        if (! in_array($this->sessionStatus($session), [
            CleaningBookingSessionStatus::Completed,
            CleaningBookingSessionStatus::Cancelled,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['A session dispute can only be opened after completion or cancellation.'],
            ]);
        }

        $activeExists = Dispute::query()
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->where('cleaning_booking_session_id', $session->id)
            ->whereIn('status', [DisputeStatus::Open->value, DisputeStatus::UnderReview->value])
            ->exists();

        if ($activeExists) {
            throw ValidationException::withMessages([
                'dispute' => ['This session already has an active dispute.'],
            ]);
        }

        return Dispute::query()->create([
            'booking_id' => $booking->id,
            'booking_type' => $booking->getMorphClass(),
            'cleaning_booking_session_id' => $session->id,
            'ticket_number' => 'DSP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'description' => mb_trim($description),
            'category' => $category->value,
            'status' => DisputeStatus::Open->value,
            'resolution' => null,
            'worker_earnings_frozen' => false,
        ]);
    }

    private function assertCustomerSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
    ): void {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        if ((int) $session->cleaning_booking_id !== (int) $booking->id) {
            throw ValidationException::withMessages([
                'sessionId' => ['Session does not belong to this booking.'],
            ]);
        }
    }

    private function sessionStatus(CleaningBookingSession $session): ?CleaningBookingSessionStatus
    {
        return $session->status instanceof CleaningBookingSessionStatus
            ? $session->status
            : CleaningBookingSessionStatus::tryFrom((string) $session->status);
    }

    private function syncWorkerAverageRating(int $workerId): void
    {
        $average = WorkerCustomerRating::query()
            ->where('worker_id', $workerId)
            ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
            ->avg('rating');

        Worker::query()->whereKey($workerId)->update([
            'average_rating' => $average !== null ? round((float) $average, 2) : 0,
        ]);
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
''',
)

write(
    "Modules/Cleaning/app/Http/Controllers/API/CleaningBookingSessionInteractionController.php",
    r'''<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\DisputeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\CleaningBookingSessionInteractionService;

final class CleaningBookingSessionInteractionController
{
    public function __construct(
        private readonly CleaningBookingSessionInteractionService $interactions,
        private readonly CleaningBookingSchedulePresenter $presenter,
    ) {}

    public function review(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'workerId' => ['required', 'integer', 'exists:workers,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $this->interactions->submitReview(
            $cleaning_booking,
            $cleaning_booking_session,
            (int) $request->user()->id,
            $validated,
        );

        return $this->payload($cleaning_booking, [
            'reviewId' => (int) $review->id,
            'workerId' => (int) $review->worker_id,
            'rating' => (int) $review->rating,
        ]);
    }

    public function openDispute(
        Request $request,
        CleaningBooking $cleaning_booking,
        CleaningBookingSession $cleaning_booking_session,
    ): JsonResponse {
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'category' => ['required', Rule::enum(DisputeCategory::class)],
        ]);

        $category = DisputeCategory::tryFrom((string) $validated['category']);
        if (! $category instanceof DisputeCategory) {
            throw ValidationException::withMessages(['category' => ['Invalid dispute category.']]);
        }

        $dispute = $this->interactions->openDispute(
            $cleaning_booking,
            $cleaning_booking_session,
            (int) $request->user()->id,
            (string) $validated['description'],
            $category,
        );

        return $this->payload($cleaning_booking, [
            'disputeId' => (int) $dispute->id,
            'ticketNumber' => $dispute->ticket_number,
            'status' => $dispute->status?->value ?? (string) $dispute->status,
        ], 201);
    }

    /** @param array<string, mixed> $interaction */
    private function payload(CleaningBooking $booking, array $interaction, int $status = 200): JsonResponse
    {
        $freshBooking = $booking->fresh();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $freshBooking->id,
                'bookingId' => (int) $freshBooking->id,
                'bookingNumber' => $freshBooking->booking_number,
                'status' => $freshBooking->status?->value ?? (string) $freshBooking->status,
                'totalPrice' => (float) $freshBooking->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                'schedule' => $this->presenter->present($freshBooking),
                'interaction' => $interaction,
            ],
        ], $status);
    }
}
''',
)

# 8) Session routes.
path = "Modules/Cleaning/routes/sessions.php"
replace_once(
    path,
    "use Modules\\Cleaning\\Http\\Controllers\\API\\CleaningBookingSessionLifecycleController;",
    "use Modules\\Cleaning\\Http\\Controllers\\API\\CleaningBookingSessionInteractionController;\nuse Modules\\Cleaning\\Http\\Controllers\\API\\CleaningBookingSessionLifecycleController;",
)
replace_once(
    path,
    "        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/sos',",
    "        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/review',\n            [CleaningBookingSessionInteractionController::class, 'review'],\n        )->name('cleaning-bookings.sessions.review');\n\n        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/disputes',\n            [CleaningBookingSessionInteractionController::class, 'openDispute'],\n        )->name('cleaning-bookings.sessions.disputes.store');\n\n        Route::post(\n            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/sos',",
)

# 9) Presenter capabilities/read model.
path = "Modules/Cleaning/app/Services/CleaningBookingSchedulePresenter.php"
replace_once(
    path,
    "namespace Modules\\Cleaning\\Services;\n\nuse App\\Models\\Worker;",
    "namespace Modules\\Cleaning\\Services;\n\nuse App\\Enums\\DisputeStatus;\nuse App\\Enums\\WorkerCustomerRatingType;\nuse App\\Models\\Dispute;\nuse App\\Models\\Worker;",
)
replace_once(
    path,
    "            ->with(['workerAssignments.worker.user'])",
    "            ->with(['workerAssignments.worker.user', 'ratings', 'disputes'])",
)
insert_anchor = """        $canSendSos = ! $session->isTerminal()\n            && $status !== CleaningBookingSessionStatus::Paused->value\n            && ($isCustomerView || $hasMyActiveAssignment);\n\n        return ["""
insert = """        $canSendSos = ! $session->isTerminal()\n            && $status !== CleaningBookingSessionStatus::Paused->value\n            && ($isCustomerView || $hasMyActiveAssignment);\n        $reviewedWorkerIds = $session->ratings\n            ->filter(static fn ($rating): bool => (string) ($rating->rating_type?->value ?? $rating->rating_type)\n                === WorkerCustomerRatingType::CustomerToWorker->value)\n            ->pluck('worker_id')\n            ->map(static fn ($workerId): int => (int) $workerId)\n            ->unique()\n            ->values();\n        $reviewableWorkerIds = $acceptedAssignments\n            ->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool =>\n                (string) ($assignment->status?->value ?? $assignment->status) === 'completed')\n            ->pluck('worker_id')\n            ->map(static fn ($workerId): int => (int) $workerId)\n            ->diff($reviewedWorkerIds)\n            ->values();\n        $activeDispute = $session->disputes->first(static function (Dispute $dispute): bool {\n            $disputeStatus = $dispute->status instanceof DisputeStatus\n                ? $dispute->status\n                : DisputeStatus::tryFrom((string) $dispute->status);\n\n            return $disputeStatus !== null && ! $disputeStatus->isTerminal();\n        });\n        $canOpenDispute = $isCustomerView\n            && in_array($status, [\n                CleaningBookingSessionStatus::Completed->value,\n                CleaningBookingSessionStatus::Cancelled->value,\n            ], true)\n            && ! $activeDispute instanceof Dispute;\n        $paymentStatus = in_array($status, [\n            CleaningBookingSessionStatus::Cancelled->value,\n            CleaningBookingSessionStatus::Skipped->value,\n            CleaningBookingSessionStatus::Superseded->value,\n        ], true)\n            ? 'not_required'\n            : ((string) ($session->payment_status ?: 'pending'));\n\n        return ["""
replace_once(path, insert_anchor, insert)
replace_once(
    path,
    "            'pricing' => [",
    "            'paymentStatus' => $paymentStatus,\n            'paymentSettledAt' => $session->payment_settled_at?->toIso8601String(),\n            'payment' => [\n                'status' => $paymentStatus,\n                'amount' => (float) $session->total_price,\n                'currency' => (string) config('app.currency', 'SYP'),\n                'settledAt' => $session->payment_settled_at?->toIso8601String(),\n                'isInternalSettlement' => true,\n            ],\n            'canReview' => $isCustomerView && $status === CleaningBookingSessionStatus::Completed->value && $reviewableWorkerIds->isNotEmpty(),\n            'hasReview' => $reviewedWorkerIds->isNotEmpty(),\n            'reviewedWorkerIds' => $reviewedWorkerIds->all(),\n            'reviewableWorkerIds' => $reviewableWorkerIds->all(),\n            'canOpenDispute' => $canOpenDispute,\n            'hasOpenDispute' => $activeDispute instanceof Dispute,\n            'disputeId' => $activeDispute instanceof Dispute ? (int) $activeDispute->id : null,\n            'disputeStatus' => $activeDispute instanceof Dispute\n                ? ($activeDispute->status?->value ?? (string) $activeDispute->status)\n                : null,\n            'pricing' => [",
)

# 10) Feature tests.
write(
    "tests/Feature/Cleaning/RecurringCleaningSessionInteractionsTest.php",
    r'''<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\WorkerCustomerRatingType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\CleaningBookingSessionInteractionService;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;

use function Pest\Laravel\postJson;

/** @return array{0:User,1:Worker,2:CleaningBooking,3:CleaningBookingSession,4:CleaningBookingSession} */
function makeRecurringSessionInteractionContext(): array
{
    CleaningDepositSetting::query()->firstOrCreate([], [
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 100,
    ]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'debt_balance' => 0,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    $start = now(config('app.timezone'))->subDay()->setTime(10, 0);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
        'property_type' => 'apartment',
        'scheduled_date' => $start->toDateString(),
        'scheduled_time' => $start->format('H:i'),
        'estimated_hours' => 4,
        'total_hours' => 4,
        'base_price' => 2000,
        'admin_margin_amount' => 200,
        'total_price' => 2200,
    ]);

    $sessions = [];
    foreach ([1, 2] as $sequence) {
        $session = CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => $sequence,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $start->copy()->addDays($sequence - 1)->toDateString(),
            'scheduled_time' => $start->format('H:i'),
            'duration_hours' => 2,
            'required_workers' => 1,
            'coverage_status' => CleaningBookingSessionCoverageStatus::FullyCovered->value,
            'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion->value,
            'base_price' => 1000,
            'admin_margin_amount' => 100,
            'total_price' => 1100,
            'is_pricing_final' => true,
            'payment_status' => 'ready',
            'work_started_at' => $start->copy()->addHours(1),
            'work_finished_at' => $start->copy()->addHours(3),
        ]);
        CleaningBookingSessionWorkerAssignment::query()->create([
            'cleaning_booking_session_id' => $session->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
            'accepted_at' => $start->copy()->subHour(),
            'work_started_at' => $start->copy()->addHour(),
            'work_finished_at' => $start->copy()->addHours(3),
            'service_share_amount' => 900,
            'travel_fee' => 0,
            'admin_margin_amount' => 100,
            'worker_amount' => 900,
            'currency' => 'SYP',
        ]);
        $sessions[] = $session;
    }

    return [$customer, $worker, $booking, $sessions[0], $sessions[1]];
}

it('settles each recurring session commission exactly once without parent double charging', function (): void {
    [$customer, $worker, $booking, $first, $second] = makeRecurringSessionInteractionContext();
    $lifecycle = app(CleaningBookingSessionLifecycleService::class);

    $first = $lifecycle->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);
    expect($first->payment_status)->toBe('settled')
        ->and($first->payment_settled_at)->not->toBeNull();

    $second = $lifecycle->confirmCompletion($booking->fresh(), $second->fresh(), (int) $customer->id);
    expect($second->payment_status)->toBe('settled')
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::Completed);

    $transactions = CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)
        ->where('reference', 'like', CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%')
        ->get();

    expect($transactions)->toHaveCount(2)
        ->and($transactions->pluck('amount')->map(fn ($amount): float => (float) $amount)->all())
        ->each->toBe(100.0);

    // Idempotent completion must not create another settlement transaction.
    $lifecycle->confirmCompletion($booking->fresh(), $second->fresh(), (int) $customer->id);
    expect(CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)
        ->where('reference', 'like', CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%')
        ->count())->toBe(2);
});

it('stores independent reviews for the same worker across recurring sessions', function (): void {
    [$customer, $worker, $booking, $first, $second] = makeRecurringSessionInteractionContext();
    $lifecycle = app(CleaningBookingSessionLifecycleService::class);
    $interactions = app(CleaningBookingSessionInteractionService::class);

    $first = $lifecycle->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);
    $second = $lifecycle->confirmCompletion($booking->fresh(), $second->fresh(), (int) $customer->id);

    $interactions->submitReview($booking->fresh(), $first, (int) $customer->id, [
        'workerId' => (int) $worker->id,
        'rating' => 5,
        'comment' => 'first visit',
    ]);
    $interactions->submitReview($booking->fresh(), $second, (int) $customer->id, [
        'workerId' => (int) $worker->id,
        'rating' => 4,
        'comment' => 'second visit',
    ]);

    expect(App\Models\WorkerCustomerRating::query()
        ->where('booking_id', $booking->id)
        ->where('worker_id', $worker->id)
        ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
        ->count())->toBe(2)
        ->and($worker->fresh()->average_rating)->toEqual(4.5);
});

it('exposes per-session review dispute and payment state through the authenticated schedule contract', function (): void {
    [$customer, $worker, $booking, $first] = makeRecurringSessionInteractionContext();
    $first = app(CleaningBookingSessionLifecycleService::class)
        ->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/review", [
        'workerId' => $worker->id,
        'rating' => 5,
        'comment' => 'great recurring visit',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.paymentStatus', 'settled')
        ->assertJsonPath('data.schedule.sessions.0.hasReview', true);

    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/disputes", [
        'description' => 'There is a billing question for this visit.',
        'category' => DisputeCategory::BillingIssue->value,
    ])->assertCreated()
        ->assertJsonPath('data.schedule.sessions.0.hasOpenDispute', true)
        ->assertJsonPath('data.schedule.sessions.0.canOpenDispute', false);

    $presented = app(CleaningBookingSchedulePresenter::class)->present($booking->fresh());
    expect($presented['sessions'][0]['reviewedWorkerIds'])->toContain((int) $worker->id)
        ->and($presented['sessions'][0]['payment']['isInternalSettlement'])->toBeTrue()
        ->and($presented['sessions'][0]['disputeStatus'])->toBe('open');
});

it('rejects a second active dispute for the same recurring session', function (): void {
    [$customer, , $booking, $first] = makeRecurringSessionInteractionContext();
    $first = app(CleaningBookingSessionLifecycleService::class)
        ->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);
    $interactions = app(CleaningBookingSessionInteractionService::class);

    $interactions->openDispute(
        $booking->fresh(),
        $first,
        (int) $customer->id,
        'First dispute',
        DisputeCategory::PoorQuality,
    );

    expect(fn () => $interactions->openDispute(
        $booking->fresh(),
        $first,
        (int) $customer->id,
        'Duplicate dispute',
        DisputeCategory::Other,
    ))->toThrow(ValidationException::class);
});
''',
)

print('Recurring session interaction patch applied.')
