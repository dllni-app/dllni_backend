<?php

declare(strict_types=1);

namespace Modules\Cleaning\Data;

use Modules\Cleaning\Models\CleaningBillingPolicy;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<CleaningBillingPolicy> */
final class CleaningBillingPolicyData extends Data
{
    use HasModelAttributes;

    protected static string $model = CleaningBillingPolicy::class;

    public function __construct(
        public ?string $name,
        public ?string $billingMode,
        public ?array $rules,
        public ?bool $isActive,
        public ?bool $isDefault,
    ) {}
}
