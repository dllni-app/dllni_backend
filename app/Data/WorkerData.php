<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Worker;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Worker> */
final class WorkerData extends Data
{
    use HasModelAttributes;

    protected static string $model = Worker::class;

    public function __construct(
        public ?int $userId,
        public ?string $firstName,
        public ?string $bio,
        public ?float $averageRating,
        public ?int $totalCompletedJobs,
        public ?int $trustScore,
        public ?float $acceptanceRate,
        public ?float $cancellationRate,
        public ?int $openDisputesCount,
        public ?bool $isActive,
        public ?bool $isSuspended,
        public ?string $suspendedUntil,
        public ?string $homeAddress,
        public ?float $homeLatitude,
        public ?float $homeLongitude,
        public ?array $defaultWorkingHours,
    ) {}
}
