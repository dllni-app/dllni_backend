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
                'name' => 'Standard Restaurant Policy',
                'description' => 'Free cancellation up to 2 hours before pickup. 50% charge within 2 hours.',
                'rules' => [
                    'free_until_hours' => 2,
                    'late_percentage' => 50,
                ],
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'module' => 'restaurant',
                'name' => 'Strict Restaurant Policy',
                'description' => 'Free cancellation up to 4 hours before. 100% charge within 4 hours.',
                'rules' => [
                    'free_until_hours' => 4,
                    'late_percentage' => 100,
                ],
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'module' => 'cleaning',
                'name' => 'Standard Cleaning Policy',
                'description' => 'Free cancellation up to 24 hours before. 25% within 24h, 50% within 12h.',
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
                'name' => 'Flexible Cleaning Policy',
                'description' => 'Free cancellation up to 12 hours before.',
                'rules' => [
                    'free_until_hours' => 12,
                ],
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'module' => 'supermarket',
                'name' => 'Standard Supermarket Policy',
                'description' => 'Free cancellation up to 1 hour before delivery.',
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
