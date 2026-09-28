<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

beforeEach(function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    Restaurant::factory()->create([
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);
});

it('extracts product data from image for restaurant products', function (): void {
    if (! config('gemini.api_key')) {
        $this->markTestSkipped('Gemini API key is not configured.');
    }

    $image = UploadedFile::fake()->image('product.jpg');

    $response = $this->postJson('/api/v1/products/ai/extract-from-image', [
        'image' => $image,
    ]);

    $response->assertOk();
    expect($response->json('data'))->not()->toBeNull();
});

it('generates product image from text for restaurant products', function (): void {
    if (! config('gemini.api_key')) {
        $this->markTestSkipped('Gemini API key is not configured.');
    }

    $response = $this->postJson('/api/v1/products/ai/generate-image', [
        'title' => 'برجر دجاج كلاسيك',
        'description' => 'وصف لذيذ للبرجر.',
    ]);

    $response->assertOk();
    expect($response->json('data'))->not()->toBeNull();
});
