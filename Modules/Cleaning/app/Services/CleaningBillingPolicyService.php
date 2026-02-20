<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Data\CleaningBillingPolicyData;
use Modules\Cleaning\Models\CleaningBillingPolicy;

final class CleaningBillingPolicyService
{
    public function store(CleaningBillingPolicyData $data): CleaningBillingPolicy
    {
        return DB::transaction(static function () use ($data) {
            $policy = CleaningBillingPolicy::create($data->onlyModelAttributes());

            return $policy;
        });
    }

    public function update(CleaningBillingPolicyData $data, CleaningBillingPolicy $policy): CleaningBillingPolicy
    {
        return DB::transaction(static function () use ($data, $policy) {
            tap($policy)->update($data->onlyModelAttributes());

            return $policy;
        });
    }
}
