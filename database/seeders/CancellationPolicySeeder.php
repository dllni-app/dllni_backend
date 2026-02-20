<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CancellationPolicy;
use Illuminate\Database\Seeder;

final class CancellationPolicySeeder extends Seeder
{
    public function run(): void
    {
        $policies = [
            [
                'module' => 'restaurant',
                'name' => 'سياسة المطعم المعيارية',
                'description' => 'إلغاء مجاني حتى ساعتين قبل الاستلام. رسوم 50% خلال ساعتين.',
                'rules' => [
                    'free_until_hours' => 2,
                    'late_percentage' => 50,
                ],
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'module' => 'restaurant',
                'name' => 'سياسة المطعم الصارمة',
                'description' => 'إلغاء مجاني حتى 4 ساعات قبل. رسوم 100% خلال 4 ساعات.',
                'rules' => [
                    'free_until_hours' => 4,
                    'late_percentage' => 100,
                ],
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'module' => 'cleaning',
                'name' => 'سياسة التنظيف المعيارية',
                'description' => 'إلغاء مجاني حتى 24 ساعة قبل. 25% خلال 24 ساعة، 50% خلال 12 ساعة.',
                'rules' => [
                    'free_until_hours' => 24,
                    'within_24h_percentage' => 25,
                    'within_12h_percentage' => 50,
                ],
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'module' => 'cleaning',
                'name' => 'سياسة التنظيف المرنة',
                'description' => 'إلغاء مجاني حتى 12 ساعة قبل.',
                'rules' => [
                    'free_until_hours' => 12,
                ],
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'module' => 'supermarket',
                'name' => 'سياسة السوبرماركت المعيارية',
                'description' => 'إلغاء مجاني حتى ساعة واحدة قبل التوصيل.',
                'rules' => [
                    'free_until_hours' => 1,
                ],
                'is_active' => true,
                'is_default' => true,
            ],
        ];

        foreach ($policies as $policy) {
            CancellationPolicy::firstOrCreate(
                [
                    'module' => $policy['module'],
                    'name' => $policy['name'],
                ],
                $policy
            );
        }
    }
}
