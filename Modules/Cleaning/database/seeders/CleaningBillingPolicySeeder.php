<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;

final class CleaningBillingPolicySeeder extends Seeder
{
    public function run(): void
    {
        $policies = [
            [
                'name' => 'الوقت المحجوز كاملاً',
                'billing_mode' => CleaningBillingMode::FullBookedTime->value,
                'rules' => [
                    'charge_full_booked_hours' => true,
                    'overtime_rate' => 1.5,
                ],
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'name' => 'وقت العمل الفعلي',
                'billing_mode' => CleaningBillingMode::ActualWorkingTime->value,
                'rules' => [
                    'charge_actual_hours' => true,
                    'min_billable_hours' => 1,
                ],
                'is_active' => true,
                'is_default' => false,
            ],
        ];

        foreach ($policies as $policy) {
            CleaningBillingPolicy::firstOrCreate(
                ['name' => $policy['name']],
                $policy
            );
        }
    }
}
