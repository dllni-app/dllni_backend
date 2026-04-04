<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

final class VerifiedUserSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguard();

        $user = User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'User',
                'phone' => '+963944000222',
                'email' => 'user@example.com',
                'password' => bcrypt('secret123'),
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
            ],
        );

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            "https://picsum.photos/seed/user-{$user->id}-primary/600/600",
            "user-{$user->id}-primary"
        );
    }
}
