<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmStoreDocument;

final class SmStoreDocumentFactory extends Factory
{
    protected $model = SmStoreDocument::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'document_type' => fake()->randomElement(['identity', 'commercial_registration', 'health_certificate', 'other']),
            'file_path' => 'documents/'.fake()->uuid().'.pdf',
            'verification_status' => fake()->randomElement(['pending', 'approved', 'rejected']),
            'rejection_reason' => null,
            'verified_by_user_id' => User::factory(),
            'verified_at' => now(),
            'expires_at' => now()->addMonths(6),
        ];
    }
}
