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

        $this->seedVerifiedUser(
            name: 'مستخدم التطبيق',
            phone: '+963944000222',
            email: 'user@dllni.sy',
            imageUrl: 'https://images.unsplash.com/photo-1545167622-3a6ac756afa4?auto=format&fit=crop&w=600&q=80'
        );

        $this->seedVerifiedUser(
            name: 'مستخدم التطبيق 2',
            phone: '+963944000223',
            email: 'user2@dllni.sy',
            imageUrl: 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=600&q=80'
        );
    }

    private function seedVerifiedUser(string $name, string $phone, string $email, string $imageUrl): void
    {
        $user = User::query()
            ->where('phone', $phone)
            ->orWhere('email', $email)
            ->first();

        if ($user === null) {
            $user = new User();
        }

        $user->forceFill([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ])->save();

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            $imageUrl,
            "user-{$user->id}-primary"
        );
    }
}
