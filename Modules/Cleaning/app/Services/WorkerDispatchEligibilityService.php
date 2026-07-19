<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;

final class WorkerDispatchEligibilityService
{
    public const REASON_ELIGIBLE = 'eligible';
    public const REASON_WORKER_INACTIVE = 'worker_inactive';
    public const REASON_WORKER_SUSPENDED = 'worker_suspended';
    public const REASON_TRUST_SCORE_TOO_LOW = 'trust_score_too_low';
    public const REASON_DEPOSIT_BELOW_ALLOWED_BALANCE = 'deposit_below_allowed_balance';
    public const REASON_DEPOSIT_REQUIRED_BEFORE_START = 'deposit_required_before_start';

    public function __construct(
        private readonly DepositService $depositService,
    ) {}

    /**
     * @return array{
     *     canReceiveNewRequests: bool,
     *     canAcceptNewBookings: bool,
     *     canStartAssignedWork: bool,
     *     status: string,
     *     reasonCode: string,
     *     startWorkReasonCode: string,
     *     title: string,
     *     message: string,
     *     action: array{type: string|null, label: string|null},
     *     depositSummary: array<string, mixed>
     * }
     */
    public function forNewRequests(Worker $worker): array
    {
        $worker->loadMissing('deposit');

        $depositSummary = $this->depositService->depositStatusPayload($worker);
        $canReceiveNewRequests = (bool) ($depositSummary['isEligibleForNewRequests'] ?? false);
        $canStartAssignedWork = $this->depositService->isWorkerEligibleToStartWork($worker);
        $reasonCode = $this->resolveNewRequestReasonCode($worker, $depositSummary, $canReceiveNewRequests);
        $startWorkReasonCode = $canStartAssignedWork
            ? self::REASON_ELIGIBLE
            : $this->resolveStartWorkReasonCode($worker, $depositSummary);

        return [
            'canReceiveNewRequests' => $canReceiveNewRequests,
            'canAcceptNewBookings' => $canReceiveNewRequests,
            'canStartAssignedWork' => $canStartAssignedWork,
            'status' => $reasonCode,
            'reasonCode' => $reasonCode,
            'startWorkReasonCode' => $startWorkReasonCode,
            'title' => $this->titleFor($reasonCode),
            'message' => $this->messageFor($reasonCode, $depositSummary),
            'action' => $this->actionFor($reasonCode),
            'depositSummary' => $depositSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $depositSummary
     */
    private function resolveNewRequestReasonCode(Worker $worker, array $depositSummary, bool $canReceiveNewRequests): string
    {
        if (! $worker->is_active) {
            return self::REASON_WORKER_INACTIVE;
        }

        if ($worker->is_suspended) {
            return self::REASON_WORKER_SUSPENDED;
        }

        if ($canReceiveNewRequests) {
            return self::REASON_ELIGIBLE;
        }

        if (($depositSummary['exceedanceAmount'] ?? null) !== null) {
            return self::REASON_DEPOSIT_BELOW_ALLOWED_BALANCE;
        }

        return self::REASON_TRUST_SCORE_TOO_LOW;
    }

    /**
     * @param  array<string, mixed>  $depositSummary
     */
    private function resolveStartWorkReasonCode(Worker $worker, array $depositSummary): string
    {
        if (! $worker->is_active) {
            return self::REASON_WORKER_INACTIVE;
        }

        if ($worker->is_suspended) {
            return self::REASON_WORKER_SUSPENDED;
        }

        if (($depositSummary['exceedanceAmount'] ?? null) !== null) {
            return self::REASON_DEPOSIT_BELOW_ALLOWED_BALANCE;
        }

        $currentBalance = (float) ($depositSummary['currentBalance'] ?? 0);
        $minimumRequired = (float) ($depositSummary['minimumRequired'] ?? 0);

        if ($minimumRequired > 0 && $currentBalance < $minimumRequired) {
            return self::REASON_DEPOSIT_REQUIRED_BEFORE_START;
        }

        return self::REASON_TRUST_SCORE_TOO_LOW;
    }

    private function titleFor(string $reasonCode): string
    {
        return match ($reasonCode) {
            self::REASON_ELIGIBLE => 'Account ready for new requests',
            self::REASON_WORKER_INACTIVE => 'Account is inactive',
            self::REASON_WORKER_SUSPENDED => 'Worker stopped by admin',
            self::REASON_TRUST_SCORE_TOO_LOW => 'Trust score is too low',
            self::REASON_DEPOSIT_BELOW_ALLOWED_BALANCE => 'Deposit balance is below the allowed limit',
            self::REASON_DEPOSIT_REQUIRED_BEFORE_START => 'Deposit balance is below the required amount',
            default => 'Account cannot receive new requests',
        };
    }

    /**
     * @param  array<string, mixed>  $depositSummary
     */
    private function messageFor(string $reasonCode, array $depositSummary): string
    {
        return match ($reasonCode) {
            self::REASON_ELIGIBLE => 'Your account can receive and accept new requests.',
            self::REASON_WORKER_INACTIVE => 'Your account is inactive. Reactivate your account to receive new requests.',
            self::REASON_WORKER_SUSPENDED => 'Your worker account was stopped by the admin. You will not receive new orders until the admin removes the suspension.',
            self::REASON_TRUST_SCORE_TOO_LOW => 'Your trust score is below the minimum required to receive new requests.',
            self::REASON_DEPOSIT_BELOW_ALLOWED_BALANCE => sprintf(
                'Your deposit balance is below the allowed limit by %s. Please recharge your deposit account to receive new requests.',
                number_format((float) ($depositSummary['exceedanceAmount'] ?? 0), 2, '.', '')
            ),
            self::REASON_DEPOSIT_REQUIRED_BEFORE_START => sprintf(
                'Your deposit balance must be at least %s before starting assigned work.',
                number_format((float) ($depositSummary['minimumRequired'] ?? 0), 2, '.', '')
            ),
            default => 'Your account cannot receive new requests right now.',
        };
    }

    /**
     * @return array{type: string|null, label: string|null}
     */
    private function actionFor(string $reasonCode): array
    {
        return match ($reasonCode) {
            self::REASON_WORKER_INACTIVE => [
                'type' => 'open_account_status',
                'label' => 'Reactivate account',
            ],
            self::REASON_DEPOSIT_BELOW_ALLOWED_BALANCE,
            self::REASON_DEPOSIT_REQUIRED_BEFORE_START => [
                'type' => 'open_deposit',
                'label' => 'View deposit account',
            ],
            self::REASON_WORKER_SUSPENDED,
            self::REASON_TRUST_SCORE_TOO_LOW => [
                'type' => 'contact_support',
                'label' => 'Contact support',
            ],
            default => [
                'type' => null,
                'label' => null,
            ],
        };
    }
}
