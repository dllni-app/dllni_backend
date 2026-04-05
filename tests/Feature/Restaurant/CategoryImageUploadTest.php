<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;

describe('Category Image Upload', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());
    });

    it('uploads image when creating a category', function (): void {
        $restaurant = Restaurant::factory()->create();
        $image = UploadedFile::fake()->image('category.jpg', 100, 100);

        $response = $this->postJson('/api/v1/categories', [
            'restaurantId' => $restaurant->id,
            'name' => 'Appetizers',
            'slug' => 'appetizers',
            'categoryImage' => $image,
        ]);

        expect($response->status())->toBeIn([200, 201]);

        $category = Category::latest()->first();
        expect($category)->not->toBeNull();
        expect($category->getMedia('category-image'))->not->toBeEmpty();
    });

    it('uploads image when updating a category', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $image = UploadedFile::fake()->image('updated-category.jpg', 150, 150);

        $response = $this->patchJson("/api/v1/categories/{$category->id}", [
            'restaurantId' => $restaurant->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'categoryImage' => $image,
        ]);

        $response->assertOk();
        $category->refresh();
        expect($category->getMedia('category-image'))->not->toBeEmpty();
    });

    it('validates image file type', function (): void {
        $restaurant = Restaurant::factory()->create();
        $invalidFile = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->postJson('/api/v1/categories', [
            'restaurantId' => $restaurant->id,
            'name' => 'Main Courses',
            'slug' => 'main-courses',
            'categoryImage' => $invalidFile,
        ]);

        $response->assertUnprocessable();
    });

    it('validates image file size', function (): void {
        $restaurant = Restaurant::factory()->create();
        // Create a file larger than 2048KB
        $largeFile = UploadedFile::fake()->create('large.jpg', 3000);

        $response = $this->postJson('/api/v1/categories', [
            'restaurantId' => $restaurant->id,
            'name' => 'Desserts',
            'slug' => 'desserts',
            'categoryImage' => $largeFile,
        ]);

        $response->assertUnprocessable();
    });

    it('allows supported image formats', function (): void {
        $restaurant = Restaurant::factory()->create();
        $supportedFormats = ['jpeg', 'jpg', 'png', 'gif', 'webp'];

        foreach ($supportedFormats as $format) {
            $image = UploadedFile::fake()->image("category.{$format}", 100, 100);

            $response = $this->postJson('/api/v1/categories', [
                'restaurantId' => $restaurant->id,
                'name' => "Category {$format}",
                'slug' => "category-{$format}",
                'categoryImage' => $image,
            ]);

            expect($response->status())->not->toBe(422);
        }
    });

    it('returns image URL in category resource', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $image = UploadedFile::fake()->image('category.jpg', 100, 100);

        // Upload image directly to category
        $category->addMedia($image)->toMediaCollection('category-image');

        $response = $this->getJson("/api/v1/categories/{$category->id}");

        $response->assertOk();
        expect($response->json('data.imageUrl'))->not->toBeNull();
        expect($response->json('data.imageUrl'))->toBeString();
    });

    it('handles category without image gracefully', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $response = $this->getJson("/api/v1/categories/{$category->id}");

        $response->assertOk();
        expect($response->json('data.imageUrl'))->toBeNull();
    });

    it('updates only image without changing other fields', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Original Name',
        ]);

        $newImage = UploadedFile::fake()->image('new-image.jpg', 100, 100);

        $response = $this->patchJson("/api/v1/categories/{$category->id}", [
            'restaurantId' => $restaurant->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'categoryImage' => $newImage,
        ]);

        $response->assertOk();
        $category->refresh();

        expect($category->name)->toBe('Original Name');
        expect($category->getMedia('category-image'))->not->toBeEmpty();
    });

    it('replaces previous image when updating', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $firstImage = UploadedFile::fake()->image('first.jpg', 100, 100);
        $category->addMedia($firstImage)->toMediaCollection('category-image');

        $secondImage = UploadedFile::fake()->image('second.jpg', 100, 100);

        $response = $this->patchJson("/api/v1/categories/{$category->id}", [
            'restaurantId' => $restaurant->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'categoryImage' => $secondImage,
        ]);

        $response->assertOk();
        $category->refresh();

        expect($category->getMedia('category-image')->count())->toBe(1);
    });
});
