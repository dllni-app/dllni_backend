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
                'name' => 'مالك السوبرماركت',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $stores = [
            [
                'name' => 'سوبرماركت النخبة',
                'slug' => 'elite-supermarket',
                'description' => 'سوبرماركت متكامل يقدم خضروات وفواكه طازجة، ألبان، لحوم، ومستلزمات منزلية. جودة عالية وأسعار منافسة.',
                'address' => 'شارع الملك عبدالله الثاني، عمان',
                'latitude' => 31.963158,
                'longitude' => 35.930359,
                'phone' => '+962 6 555 3001',
                'email' => 'info@elite-supermarket.example.com',
                'is_featured' => true,
            ],
            [
                'name' => 'سوق الخير للمواد الغذائية',
                'slug' => 'khair-food-market',
                'description' => 'جميع احتياجاتك المنزلية تحت سقف واحد. خضروات وفواكه يومية، أجبان، لحوم طازجة، ومعلبات.',
                'address' => 'طريق المطار، ماركا',
                'latitude' => 31.975000,
                'longitude' => 35.940000,
                'phone' => '+962 6 555 3002',
                'email' => 'contact@khair-market.example.com',
                'is_featured' => true,
            ],
            [
                'name' => 'بقالة الحي',
                'slug' => 'neighborhood-grocery',
                'description' => 'بقالة صغيرة لخدمة الحي. منتجات أساسية، مشروبات، وجبات خفيفة، وخدمة استلام سريعة.',
                'address' => '42 شارع الجامعة، جبل عمان',
                'latitude' => 31.955000,
                'longitude' => 35.925000,
                'phone' => '+962 6 555 3003',
                'email' => 'hello@neighborhood-grocery.example.com',
                'is_featured' => false,
            ],
        ];

        foreach ($stores as $data) {
            SmStore::firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'owner_user_id' => $owner->id,
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'address' => $data['address'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
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
