<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\GenderPreference;
use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningScheduleChangeDecision;
use Modules\Cleaning\Models\CleaningScheduleChangeRequest;

final class CleaningScheduleChangeApprovalService
{
    public function __construct(
        private readonly WorkerBookingScheduleConflictService $conflicts,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
        private readonly CleaningBookingSessionParentStateService $parentState,
    ) {}

    /** @return array<int,array{id:int,name:string,gender:?string,rating:float}> */
    public function replacementOptions(CleaningScheduleChangeRequest $request, User $customer): array
    {
        abort_unless((int) $request->customer_id === (int) $customer->id, 403, 'Change request belongs to another customer.');
        if ($request->status !== 'rejected') {
            return [];
        }

        $booking = $request->booking()->with('specialServices.specialService')->firstOrFail();
        $definitions = array_values(array_filter(array_map(static fn (mixed $session): ?array => is_array($session) ? [
            'date' => (string) ($session['date'] ?? ''),
            'time' => (string) ($session['time'] ?? ''),
            'hours' => (float) ($session['hours'] ?? $request->proposed_snapshot['sessionHours'] ?? 1),
        ] : null, (array) ($request->proposed_snapshot['sessions'] ?? []))));
        $excludedWorkerIds = $request->decisions()->pluck('worker_id')->map(fn ($id) => (int) $id)->all();
        $genderPreference = $booking->gender_preference instanceof GenderPreference
            ? $booking->gender_preference->value
            : (string) ($booking->gender_preference ?? 'any');

        $workers = Worker::query()
            ->activeAvailable()
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->when($excludedWorkerIds !== [], fn ($query) => $query->whereNotIn('id', $excludedWorkerIds))
            ->when($genderPreference !== '' && $genderPreference !== 'any', fn ($query) => $query->where('gender', $genderPreference))
            ->when($booking->neighborhood_id !== null, fn ($query) => $query->coversNeighborhood((int) $booking->neighborhood_id))
            ->with('user')
            ->orderByDesc('average_rating')
            ->limit(100)
            ->get();

        $services = $booking->specialServices
            ->map(fn ($line) => $line->specialService)
            ->filter()
            ->unique('id')
            ->values();

        return $workers->filter(function (Worker $worker) use ($definitions, $booking, $services): bool {
            foreach ($services as $service) {
                if (filled($service->gender_constraint) && (string) $service->gender_constraint !== (string) $worker->gender) {
                    return false;
                }
                $configured = DB::table('cleaning_worker_special_service_skills')
                    ->where('cleaning_special_service_id', $service->id)
                    ->exists();
                if ($configured && ! DB::table('cleaning_worker_special_service_skills')
                    ->where('cleaning_special_service_id', $service->id)
                    ->where('worker_id', $worker->id)
                    ->where('is_active', true)
                    ->exists()) {
                    return false;
                }
            }

            $this->conflicts->forgetWorker($worker);
            return ! $this->conflicts->hasConflictForDefinitions($worker, $definitions, (int) $booking->id);
        })->take(50)->map(static fn (Worker $worker): array => [
            'id' => (int) $worker->id,
            'name' => (string) ($worker->user?->name ?? $worker->first_name ?? ('Worker #'.$worker->id)),
            'gender' => filled($worker->gender) ? (string) $worker->gender : null,
            'rating' => round((float) ($worker->average_rating ?? 0), 2),
        ])->values()->all();
    }

    public function decide(CleaningScheduleChangeRequest $request, Worker $worker, string $decision, ?string $reason): CleaningScheduleChangeRequest
    {
        $result = DB::transaction(function () use ($request, $worker, $decision, $reason): CleaningScheduleChangeRequest {
            $locked = CleaningScheduleChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return $locked;
            }
            $workerDecision = CleaningScheduleChangeDecision::query()
                ->where('cleaning_schedule_change_request_id', $locked->id)
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->firstOrFail();
            $decision = $decision === 'accepted' ? 'accepted' : 'rejected';

            if ($decision === 'accepted') {
                $definitions = array_map(static fn (array $session): array => [
                    'date' => (string) ($session['date'] ?? ''),
                    'time' => (string) ($session['time'] ?? ''),
                    'hours' => (float) ($session['hours'] ?? $locked->proposed_snapshot['sessionHours'] ?? 1),
                ], (array) ($locked->proposed_snapshot['sessions'] ?? []));
                if ($this->conflicts->hasConflictForDefinitions($worker, $definitions, (int) $locked->cleaning_booking_id)) {
                    throw ValidationException::withMessages(['decision' => ['Your current schedule conflicts with the proposed sessions.']]);
                }
            }

            $workerDecision->forceFill([
                'decision' => $decision,
                'reason' => filled($reason) ? mb_substr((string) $reason, 0, 2000) : null,
                'decided_at' => now(),
            ])->save();

            if ($decision === 'rejected') {
                $locked->forceFill(['status' => 'rejected'])->save();
                return $locked;
            }

            $pendingExists = $locked->decisions()->where('decision', 'pending')->exists();
            if (! $pendingExists) {
                $this->applyApprovedChange($locked);
                $locked->forceFill(['status' => 'applied', 'resolved_at' => now()])->save();
            }

            return $locked->fresh(['decisions']) ?? $locked;
        }, 3);

        $this->parentState->refresh($result->booking);
        return $result->fresh(['decisions']) ?? $result;
    }

    public function resolveRejected(
        CleaningScheduleChangeRequest $request,
        User $customer,
        string $resolution,
        array $replacementWorkerIds,
    ): CleaningScheduleChangeRequest {
        return DB::transaction(function () use ($request, $customer, $resolution, $replacementWorkerIds): CleaningScheduleChangeRequest {
            $locked = CleaningScheduleChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->customer_id === (int) $customer->id, 403, 'Change request belongs to another customer.');
            if ($locked->status !== 'rejected') {
                throw ValidationException::withMessages(['resolution' => ['Only a rejected change request needs customer resolution.']]);
            }

            if ($resolution === 'revert') {
                $locked->forceFill(['status' => 'cancelled', 'customer_resolution' => 'revert', 'resolved_at' => now()])->save();
                return $locked;
            }

            $sessionIds = array_map('intval', (array) $locked->affected_session_ids);
            if ($resolution === 'cancel') {
                CleaningBookingSessionWorkerAssignment::query()
                    ->whereIn('cleaning_booking_session_id', $sessionIds)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->update([
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value,
                        'released_at' => now(),
                        'released_reason' => 'Customer cancelled after a recurring schedule change was rejected.',
                    ]);
                CleaningBookingSession::query()->whereIn('id', $sessionIds)->update([
                    'status' => CleaningBookingSessionStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Customer cancelled after a rejected schedule change.',
                    'cancelled_by_role' => 'customer',
                ]);
                $locked->forceFill(['status' => 'resolved', 'customer_resolution' => 'cancel', 'resolved_at' => now()])->save();
                $this->financialAggregation->sync($locked->booking);
                return $locked;
            }

            $replacementWorkerIds = array_values(array_unique(array_filter(array_map('intval', $replacementWorkerIds), fn (int $id) => $id > 0)));
            if ($resolution !== 'replace' || $replacementWorkerIds === []) {
                throw ValidationException::withMessages(['replacementWorkerIds' => ['Select at least one replacement worker.']]);
            }

            $this->applyApprovedChange($locked);
            $targetSessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $locked->cleaning_booking_id)
                ->where(function ($query) use ($locked): void {
                    foreach ((array) ($locked->proposed_snapshot['sessions'] ?? []) as $definition) {
                        if (! is_array($definition)) {
                            continue;
                        }
                        $query->orWhere(function ($slot) use ($definition): void {
                            $slot->whereDate('scheduled_date', (string) ($definition['date'] ?? ''))
                                ->where('scheduled_time', (string) ($definition['time'] ?? ''));
                        });
                    }
                })
                ->whereNotIn('status', [CleaningBookingSessionStatus::Superseded->value, CleaningBookingSessionStatus::Cancelled->value])
                ->get();
            foreach ($targetSessions as $session) {
                $session->workerAssignments()
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->update([
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value,
                        'released_at' => now(),
                        'released_reason' => 'Customer selected replacement workers after rejecting a schedule change.',
                    ]);
                foreach (array_slice($replacementWorkerIds, 0, $session->requiredWorkerCount()) as $workerId) {
                    $worker = Worker::query()->findOrFail($workerId);
                    if ($this->conflicts->hasConflictForDefinitions($worker, [[
                        'date' => $session->scheduled_date?->toDateString() ?? (string) $session->scheduled_date,
                        'time' => (string) $session->scheduled_time,
                        'hours' => (float) $session->duration_hours,
                    ]], (int) $locked->cleaning_booking_id)) {
                        throw ValidationException::withMessages([
                            'replacementWorkerIds' => ["Worker {$workerId} has a conflict with a proposed session."],
                        ]);
                    }
                    CleaningBookingSessionWorkerAssignment::query()->updateOrCreate([
                        'cleaning_booking_session_id' => $session->id,
                        'worker_id' => $workerId,
                    ], ['status' => CleaningBookingWorkerAssignmentStatus::Pending->value]);
                }
                $session->forceFill(['coverage_status' => CleaningBookingSessionCoverageStatus::Searching])->save();
            }
            $locked->forceFill(['status' => 'resolved', 'customer_resolution' => 'replace', 'resolved_at' => now()])->save();
            return $locked;
        }, 3);
    }

    private function applyApprovedChange(CleaningScheduleChangeRequest $request): void
    {
        $booking = CleaningBooking::query()->whereKey($request->cleaning_booking_id)->lockForUpdate()->firstOrFail();
        $existing = CleaningBookingSession::query()
            ->whereIn('id', array_map('intval', (array) $request->affected_session_ids))
            ->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')
            ->lockForUpdate()->get()->values();
        $proposed = array_values((array) ($request->proposed_snapshot['sessions'] ?? []));
        $pricing = (array) ($request->proposed_snapshot['singleVisitPricing'] ?? []);
        $common = min($existing->count(), count($proposed));

        // Free the unique booking/date/time slots before applying swaps. The
        // temporary dates are transaction-local and never exposed to clients.
        for ($index = 0; $index < $common; $index++) {
            $temporaryDate = CarbonImmutable::create(2090, 1, 1)->addDays($index);
            while (CleaningBookingSession::query()
                ->where('cleaning_booking_id', $booking->id)
                ->whereKeyNot($existing[$index]->id)
                ->whereDate('scheduled_date', $temporaryDate->toDateString())
                ->where('scheduled_time', '00:00')
                ->exists()) {
                $temporaryDate = $temporaryDate->addYear();
            }
            CleaningBookingSession::query()->whereKey($existing[$index]->id)->update([
                'scheduled_date' => $temporaryDate->toDateString(),
                'scheduled_time' => '00:00',
            ]);
        }

        for ($index = 0; $index < $common; $index++) {
            $session = $existing[$index];
            $definition = $proposed[$index];
            $session->forceFill([
                'scheduled_date' => $definition['date'],
                'scheduled_time' => $definition['time'],
                'duration_hours' => (float) ($definition['hours'] ?? $request->proposed_snapshot['sessionHours'] ?? $session->duration_hours),
                'base_price' => (float) ($pricing['basePrice'] ?? $session->base_price),
                'addons_total' => (float) ($pricing['addonsTotal'] ?? $session->addons_total),
                'travel_fee' => (float) ($pricing['travelFee'] ?? $session->travel_fee),
                'admin_margin_amount' => (float) ($pricing['adminMargin'] ?? $session->admin_margin_amount),
                'total_price' => (float) ($definition['totalPrice'] ?? $pricing['totalPrice'] ?? $session->total_price),
                'is_pricing_final' => (bool) ($pricing['isPricingFinal'] ?? $session->is_pricing_final),
                'version' => max(1, (int) $session->version) + 1,
            ])->save();
        }

        for ($index = $common; $index < $existing->count(); $index++) {
            $session = $existing[$index];
            $session->workerAssignments()->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())->update([
                'status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value,
                'released_at' => now(),
                'released_reason' => 'Removed by an approved recurring schedule change.',
            ]);
            $session->forceFill(['status' => CleaningBookingSessionStatus::Superseded, 'version' => (int) $session->version + 1])->save();
        }

        $maxSequence = (int) $booking->sessions()->max('sequence');
        for ($index = $common; $index < count($proposed); $index++) {
            $definition = $proposed[$index];
            CleaningBookingSession::query()->create([
                'cleaning_booking_id' => $booking->id,
                'sequence' => ++$maxSequence,
                'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
                'calculation_mode' => $request->proposed_snapshot['calculationMode'] ?? 'task',
                'scheduled_date' => $definition['date'],
                'scheduled_time' => $definition['time'],
                'duration_hours' => (float) ($definition['hours'] ?? $request->proposed_snapshot['sessionHours'] ?? 1),
                'required_workers' => max(1, (int) $booking->number_of_workers),
                'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                'status' => CleaningBookingSessionStatus::Scheduled,
                'base_price' => (float) ($pricing['basePrice'] ?? 0),
                'addons_total' => (float) ($pricing['addonsTotal'] ?? 0),
                'travel_fee' => (float) ($pricing['travelFee'] ?? 0),
                'admin_margin_amount' => (float) ($pricing['adminMargin'] ?? 0),
                'total_price' => (float) ($definition['totalPrice'] ?? $pricing['totalPrice'] ?? 0),
                'is_pricing_final' => (bool) ($pricing['isPricingFinal'] ?? false),
                'version' => 1,
            ]);
        }

        $this->financialAggregation->sync($booking);
    }
}
