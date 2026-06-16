<?php

declare(strict_types=1);

namespace Modules\User\Data;

use Carbon\CarbonImmutable;

final readonly class OtpIssueData
{
    public function __construct(
        public string $code,
        public CarbonImmutable $expiresAt,
    ) {}
}
