<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\SystemAlert;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<SystemAlert> */
final class SystemAlertData extends Data
{
    use HasModelAttributes;

    protected static string $model = SystemAlert::class;

    public function __construct(
        public ?int $bookingId,
        public ?string $bookingType,
        public ?string $alertType,
        public ?string $severity,
        public ?string $status,
        public ?array $payload,
    ) {}
}
