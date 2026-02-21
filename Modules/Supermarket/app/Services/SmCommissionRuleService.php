<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmCommissionRuleData;
use Modules\Supermarket\Models\SmCommissionRule;

final class SmCommissionRuleService
{
    public function store(SmCommissionRuleData $data): SmCommissionRule
    {
        return DB::transaction(static function () use ($data) {
            // If this rule is set as default for the store, unset other defaults
            if ($data->isDefault && $data->storeId) {
                SmCommissionRule::where('store_id', $data->storeId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $rule = SmCommissionRule::create($data->onlyModelAttributes());

            return $rule;
        });
    }

    public function update(SmCommissionRuleData $data, SmCommissionRule $rule): SmCommissionRule
    {
        return DB::transaction(static function () use ($data, $rule) {
            // If this rule is being set as default, unset other defaults for the store
            if ($data->isDefault && $rule->store_id) {
                SmCommissionRule::where('store_id', $rule->store_id)
                    ->where('id', '!=', $rule->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            tap($rule)->update($data->onlyModelAttributes());

            return $rule;
        });
    }
}
