<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Requests\CleaningTimeWarningAcceptRequest;
use Modules\Cleaning\Http\Requests\CleaningTimeWarningRejectRequest;
use Modules\Cleaning\Http\Requests\CleaningTimeWarningRequests\CleaningTimeWarningFilterRequest;
use Modules\Cleaning\Http\Resources\CleaningTimeWarningResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Models\EventBooking;
use Modules\Cleaning\Services\CleaningTimeWarningService;
use Throwable;

final class CleaningTimeWarningController
{
    public function __construct(
        private CleaningTimeWarningService $cleaningTimeWarningService,
    ) {}

    public function index(CleaningTimeWarningFilterRequest $request): AnonymousResourceCollection
    {
        $warnings = CleaningTimeWarning::getQuery()
            ->with(['booking']);

        $filters = (array) $request->input('filter', []);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $worker = $user->worker;

        if ($worker !== null && ! $this->canReadAllWarnings($user)) {
            $warnings->where(function (Builder $query) use ($worker): void {
                $query->where(function (Builder $cleaningQuery) use ($worker): void {
                    $cleaningQuery
                        ->where('booking_type', 'cleaning_booking')
                        ->where(function (Builder $warningQuery) use ($worker): void {
                            $warningQuery->where('worker_id', $worker->id)
                                ->orWhereNull('worker_id');
                        })
                        ->whereHasMorph('booking', [CleaningBooking::class], function (Builder $bookingQuery) use ($worker): void {
                            $bookingQuery->where('worker_id', $worker->id)
                                ->orWhereHas('workerAssignments', function (Builder $assignmentQuery) use ($worker): void {
                                    $assignmentQuery
                                        ->where('worker_id', $worker->id)
                                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                                })
                                ->orWhereHas('sessions.workerAssignments', function (Builder $assignmentQuery) use ($worker): void {
                                    $assignmentQuery
                                        ->where('worker_id', $worker->id)
                                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                                });
                        });
                })->orWhere(function (Builder $eventQuery) use ($worker): void {
                    $eventQuery
                        ->where('booking_type', 'event_booking')
                        ->where('worker_id', $worker->id)
                        ->whereHasMorph('booking', [EventBooking::class]);
                });
            });
        }

        if ($worker !== null && ! $this->canReadAllWarnings($user) && ! array_key_exists('pending', $filters)) {
            $warnings->whereNull('worker_responded_at');
        }

        if ($worker === null && ! $this->canReadAllWarnings($user)) {
            $warnings->whereHasMorph('booking', [CleaningBooking::class, EventBooking::class],
                static fn (Builder $bookingQuery) => $bookingQuery->where('customer_id', $user->getKey()));
        }

        return CleaningTimeWarningResource::collection(
            $warnings->paginate($request->get('perPage', 20))
        );
    }

    public function show(CleaningTimeWarning $cleaning_time_warning): CleaningTimeWarningResource
    {
        $this->ensureCanViewWarning($cleaning_time_warning);
        $cleaning_time_warning->load(['booking']);

        return CleaningTimeWarningResource::make($cleaning_time_warning);
    }

    /** @throws Throwable */
    public function accept(CleaningTimeWarningAcceptRequest $request, CleaningTimeWarning $cleaning_time_warning): CleaningTimeWarningResource
    {
        $this->ensureWorkerOwnsWarning($cleaning_time_warning);

        try {
            $warning = $this->cleaningTimeWarningService->accept(
                $cleaning_time_warning,
                $request->validated('additionalMinutes')
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['warning' => [$e->getMessage()]]);
        }

        return CleaningTimeWarningResource::make($warning->load(['booking']));
    }

    /** @throws Throwable */
    public function reject(CleaningTimeWarningRejectRequest $request, CleaningTimeWarning $cleaning_time_warning): CleaningTimeWarningResource
    {
        $this->ensureWorkerOwnsWarning($cleaning_time_warning);

        try {
            $warning = $this->cleaningTimeWarningService->reject(
                $cleaning_time_warning,
                $request->validated('message')
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['warning' => [$e->getMessage()]]);
        }

        return CleaningTimeWarningResource::make($warning->load(['booking']));
    }

    private function ensureCanViewWarning(CleaningTimeWarning $warning): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $booking = $warning->booking;
        abort_unless($booking instanceof CleaningBooking || $booking instanceof EventBooking, 404);

        if ($this->canReadAllWarnings($user) || (int) $booking->customer_id === (int) $user->getKey()) {
            return;
        }

        $worker = $user->worker;
        if ($worker !== null) {
            $workerId = (int) $worker->getKey();
            if ($warning->booking_type === 'event_booking'
                && $booking instanceof EventBooking
                && (int) ($warning->worker_id ?? 0) === $workerId) {
                return;
            }

            if ($warning->booking_type === 'cleaning_booking'
                && $booking instanceof CleaningBooking
                && ($warning->worker_id === null || (int) $warning->worker_id === $workerId)
                && $this->warningBelongsToWorker($warning, $booking, $workerId)) {
                return;
            }
        }

        abort(404, 'Extension request not found.');
    }

    private function canReadAllWarnings(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'Super Admin', 'Cleaning Ops Manager']);
    }

    private function ensureWorkerOwnsWarning(CleaningTimeWarning $warning): void
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            abort(403, 'لا يوجد حساب عامل مرتبط بالمستخدم الحالي.');
        }

        $booking = $warning->booking;

        if ($warning->booking_type === 'event_booking') {
            if ($warning->worker_id === null || (int) $warning->worker_id !== (int) $worker->id) {
                abort(403, 'طلب التمديد مخصص لعامل آخر.');
            }

            if (! $booking instanceof EventBooking) {
                abort(403, 'طلب التمديد غير مرتبط بهذا الطلب.');
            }

            return;
        }

        if ($warning->booking_type !== 'cleaning_booking') {
            abort(403, 'طلب التمديد غير صالح لخدمة التنظيف.');
        }

        if (! $booking instanceof CleaningBooking) {
            abort(403, 'طلب التمديد غير مرتبط بطلب تنظيف صالح.');
        }

        if ($warning->worker_id !== null) {
            if ((int) $warning->worker_id !== (int) $worker->id) {
                abort(403, 'طلب التمديد مخصص لعامل آخر.');
            }

            // The warning's worker_id is assigned by the backend from the
            // completion/session assignment, so it is the authoritative target.
            return;
        }

        if (! $this->warningBelongsToWorker($warning, $booking, $worker->id)) {
            abort(403, 'هذا العامل غير مرتبط بطلب التمديد الحالي.');
        }
    }

    private function warningBelongsToWorker(
        CleaningTimeWarning $warning,
        CleaningBooking $booking,
        int $workerId,
    ): bool {
        if ($warning->cleaning_booking_session_id !== null) {
            return $booking->sessions()
                ->whereKey((int) $warning->cleaning_booking_session_id)
                ->whereHas('workerAssignments', function (Builder $assignmentQuery) use ($workerId): void {
                    $assignmentQuery
                        ->where('worker_id', $workerId)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })
                ->exists();
        }

        if ($booking->worker_id === $workerId) {
            return true;
        }

        if ($booking->workerAssignments()
            ->where('worker_id', $workerId)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists()) {
            return true;
        }

        return $booking->sessions()
            ->whereHas('workerAssignments', function (Builder $assignmentQuery) use ($workerId): void {
                $assignmentQuery
                    ->where('worker_id', $workerId)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->exists();
    }
}
