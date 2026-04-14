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

        $user = User::query()
            ->where('phone', '+963944000222')
            ->orWhere('email', 'user@dllni.sy')
            ->first();

        if ($user === null) {
            $user = new User();
        }

        $user->forceFill([
            'name' => 'مستخدم التطبيق',
            'phone' => '+963944000222',
            'email' => 'user@dllni.sy',
            'password' => bcrypt('password'),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ])->save();

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            'https://images.unsplash.com/photo-1545167622-3a6ac756afa4?auto=format&fit=crop&w=600&q=80',
            "user-{$user->id}-primary"
        );
    }
}
