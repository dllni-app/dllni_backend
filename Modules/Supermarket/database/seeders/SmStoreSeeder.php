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
            ['email' => 'supermarket.owner@dllni.sy'],
            [
                'name' => '�T�?��~§�T�?z�T�' �~§�T�?z�~³�T�?�~¨�~±�T�?��~§�~±�T�'�~ª',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $stores = [
            [
                'name' => 'س�^بر �.ار�fت ا�"أطرش',
                'slug' => 'supermarket-al-atrash',
                'description' => '�.خب�^زات �?� �.ع�"بات �?� �.�?ظفات �?� أ�"با�? �^ تسا�"�S',
                'address' => 'ا�"فر�,ا�?�O شارع عبد ا�"�,ادر ا�"صا�"ح�O ح�"ب',
                'city' => 'ح�"ب',
                'neighborhood' => 'ا�"فر�,ا�?',
                'latitude' => 36.2021,
                'longitude' => 37.1343,
                'phone' => '+963 11 555 3001',
                'email' => 'info@atrash-market.dllni.sy',
                'cover' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1400&q=80',
                'logo' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=512&q=80',
                'is_featured' => true,
            ],
            [
                'name' => 'س�^بر �.ار�fت ا�"س�"طا�?',
                'slug' => 'supermarket-al-sultan',
                'description' => 'خضار �?� ف�^ا�f�? �?� أ�"با�? �?� �.�?ظفات �?� أد�^ات �.�?ز�"�Sة',
                'address' => 'ا�"ح�.دا�?�Sة�O شارع ا�"�,دس�O ح�"ب',
                'city' => 'ح�"ب',
                'neighborhood' => 'ا�"ح�.دا�?�Sة',
                'latitude' => 36.1795,
                'longitude' => 37.1082,
                'phone' => '+963 11 555 3002',
                'email' => 'hello@alsultan-market.dllni.sy',
                'cover' => 'https://images.unsplash.com/photo-1516594915697-87eb3b1c14ea?auto=format&fit=crop&w=1400&q=80',
                'logo' => 'https://images.unsplash.com/photo-1556740749-887f6717d7e4?auto=format&fit=crop&w=512&q=80',
                'is_featured' => true,
            ],
            [
                'name' => 'س�^بر �.ار�fت ا�"�?�^ر',
                'slug' => 'supermarket-al-noor',
                'description' => '�.ع�"بات �?� �.�?ظفات �?� أ�"با�? �^ تسا�"�S',
                'address' => 'ا�"سر�Sا�? ا�"جد�Sدة�O شارع تشر�S�?�O ح�"ب',
                'city' => 'ح�"ب',
                'neighborhood' => 'ا�"سر�Sا�? ا�"جد�Sدة',
                'latitude' => 36.2168,
                'longitude' => 37.1317,
                'phone' => '+963 11 555 3003',
                'email' => 'contact@alnoor-market.dllni.sy',
                'cover' => 'https://images.unsplash.com/photo-1578916171728-46686eac8d58?auto=format&fit=crop&w=1400&q=80',
                'logo' => 'https://images.unsplash.com/photo-1604719312566-8912e9227c6a?auto=format&fit=crop&w=512&q=80',
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


