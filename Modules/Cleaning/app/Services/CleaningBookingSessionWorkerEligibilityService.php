<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\GenderPreference;
use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingSessionWorkerEligibilityService
{
    public function __construct(
        private readonly CleaningBookingSessionSolvencyService $solvencyService,
    ) {}

    /** @return array{eligible:bool,reasonCode:string,message:string} */
    public function check(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): array {
        $worker->loadMissing(['user', 'deposit']);

        if (! (bool) $worker->is_active) {
            return $this->blocked('worker_inactive', 'Worker account is inactive.');
        }

        if ((bool) $worker->is_suspended) {
            return $this->blocked('worker_suspended', 'Worker account is suspended.');
        }

        if ($worker->user === null || ! (bool) $worker->user->is_active) {
            return $this->blocked('worker_user_inactive', 'Worker user account is inactive.');
        }

        $genderPreference = $booking->gender_preference instanceof GenderPreference
            ? $booking->gender_preference
            : GenderPreference::tryFrom((string) ($booking->gender_preference ?? 'any'));

        if (
            $genderPreference instanceof GenderPreference
            && $genderPreference !== GenderPreference::Any
            && (string) $worker->gender !== $genderPreference->value
        ) {
            return $this->blocked('gender_mismatch', 'Worker gender does not match the booking requirement.');
        }

        if (
            $booking->neighborhood_id !== null
            && ! Worker::query()
                ->whereKey($worker->id)
                ->coversNeighborhood((int) $booking->neighborhood_id)
                ->exists()
        ) {
            return $this->blocked('neighborhood_not_covered', 'Worker does not cover the booking neighborhood.');
        }

        $startsAt = $session->startsAt();
        if ($startsAt === null) {
            return $this->blocked('invalid_schedule', 'Session schedule is invalid.');
        }

        if (! $worker->isAvailableAt($startsAt)) {
            return $this->blocked('worker_not_available', 'Worker is not available during this session.');
        }

        $capacity = $this->solvencyService->capacitySummary($worker);
        if (! (bool) ($capacity['isEligibleForNewSessionRequests'] ?? false)) {
            return $this->blocked(
                (string) ($capacity['financialWarningCode'] ?? 'worker_not_financially_eligible'),
                (string) ($capacity['financialWarningMessage'] ?? 'Worker is not financially eligible for new requests.'),
            );
        }

        return [
            'eligible' => true,
            'reasonCode' => 'eligible',
            'message' => 'Worker is eligible for this session.',
        ];
    }

    /** @return array{eligible:bool,reasonCode:string,message:string} */
    private function blocked(string $reasonCode, string $message): array
    {
        return [
            'eligible' => false,
            'reasonCode' => $reasonCode,
            'message' => $message,
        ];
    }
}
