<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Supermarket\Models\SmStore;

final class SmStoreSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'supermarket.owner@example.com'],
            [
                'name' => 'Ù…Ø§Ù„Ùƒ Ø§Ù„Ø³ÙˆØ¨Ø±Ù…Ø§Ø±ÙƒØª',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $stores = [
            [
                'name' => 'سوبر ماركت الأطرش',
                'slug' => 'supermarket-al-atrash',
                'description' => 'مخبوزات • معلبات • منظفات • ألبان و تسالي',
                'address' => 'المزة، شارع الجلاء، دمشق',
                'city' => 'دمشق',
                'neighborhood' => 'المزة',
                'latitude' => 33.5138,
                'longitude' => 36.2765,
                'phone' => '+963 11 555 3001',
                'email' => 'info@atrash-market.example.com',
                'cover' => 'https://example.com/sm/atrash/cover.jpg',
                'logo' => 'https://example.com/sm/atrash/logo.png',
                'is_featured' => true,
            ],
            [
                'name' => 'سوبر ماركت السلطان',
                'slug' => 'supermarket-al-sultan',
                'description' => 'خضار • فواكه • ألبان • منظفات • أدوات منزلية',
                'address' => 'البرامكة، شارع الثورة، دمشق',
                'city' => 'دمشق',
                'neighborhood' => 'البرامكة',
                'latitude' => 33.5105,
                'longitude' => 36.2798,
                'phone' => '+963 11 555 3002',
                'email' => 'hello@alsultan-market.example.com',
                'cover' => 'https://example.com/sm/sultan/cover.jpg',
                'logo' => 'https://example.com/sm/sultan/logo.png',
                'is_featured' => true,
            ],
            [
                'name' => 'سوبر ماركت النور',
                'slug' => 'supermarket-al-noor',
                'description' => 'معلبات • منظفات • ألبان و تسالي',
                'address' => 'المالكي، شارع بغداد، دمشق',
                'city' => 'دمشق',
                'neighborhood' => 'المالكي',
                'latitude' => 33.5162,
                'longitude' => 36.2842,
                'phone' => '+963 11 555 3003',
                'email' => 'contact@alnoor-market.example.com',
                'cover' => 'https://example.com/sm/noor/cover.jpg',
                'logo' => 'https://example.com/sm/noor/logo.png',
                'is_featured' => false,
            ],
        ];

        foreach ($stores as $data) {
            SmStore::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'owner_user_id' => $owner->id,
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'address' => $data['address'],
                    'city' => $data['city'] ?? null,
                    'neighborhood' => $data['neighborhood'] ?? null,
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                    'cover' => $data['cover'] ?? null,
                    'logo' => $data['logo'] ?? null,
                    'average_rating' => fake()->randomFloat(2, 4.0, 4.9),
                    'total_reviews' => fake()->numberBetween(30, 400),
                    'trust_score' => fake()->numberBetween(80, 100),
                    'is_active' => true,
                    'is_featured' => $data['is_featured'],
                ]
            );
        }
    }
}
