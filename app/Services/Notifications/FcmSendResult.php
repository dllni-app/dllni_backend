<?php

declare(strict_types=1);

namespace App\Services\Notifications;

final readonly class FcmSendResult
{
    public function __construct(
        public bool $success,
        public bool $invalidToken = false,
        public int $httpStatus = 0,
        public int $durationMs = 0,
        public ?string $messageId = null,
        public ?string $errorCode = null,
    ) {}
}
