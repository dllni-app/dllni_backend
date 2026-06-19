<?php

declare(strict_types=1);

namespace Modules\Cleaning\Exceptions;

use InvalidArgumentException;

final class WorkerDispatchEligibilityException extends InvalidArgumentException
{
    /**
     * @param  array<string, mixed>  $eligibility
     */
    public function __construct(
        private readonly array $eligibility,
    ) {
        parent::__construct((string) ($eligibility['message'] ?? 'Your account cannot accept new requests right now.'));
    }

    /**
     * @param  array<string, mixed>  $eligibility
     */
    public static function fromEligibility(array $eligibility): self
    {
        return new self($eligibility);
    }

    /**
     * @return array<string, mixed>
     */
    public function eligibility(): array
    {
        return $this->eligibility;
    }

    public function reasonCode(): string
    {
        return (string) ($this->eligibility['reasonCode'] ?? 'worker_not_eligible_for_new_requests');
    }

    /**
     * @return array<string, mixed>
     */
    public function errorPayload(): array
    {
        return [
            'code' => 'WORKER_NOT_ELIGIBLE_FOR_NEW_REQUESTS',
            'reasonCode' => $this->reasonCode(),
            'message' => $this->getMessage(),
            'action' => $this->eligibility['action'] ?? null,
            'dispatchEligibility' => $this->eligibility,
        ];
    }
}
