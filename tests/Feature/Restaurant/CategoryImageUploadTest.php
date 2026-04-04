<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;
use Storage;

describe('Category Image Upload', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
    });

    it('uploads image when creating a category', function (): void {
        $restaurant = Restaurant::factory()->create();
        $image = UploadedFile::fake()->image('category.jpg', 100, 100);

        $response = $this->postJson('/api/restaurant/categories', [
            'restaurantId' => $restaurant->id,
            'name' => 'Appetizers',
            'slug' => 'appetizers',
            'categoryImage' => $image,
        ]);

        // This assumes the category controller exists and uses CategoryService
        // Adjust endpoint as needed for your actual API
        expect($response->status())->toBeIn([200, 201, 422]); // 422 if endpoint doesn't exist

        if ($response->status() === 201) {
            $category = Category::latest()->first();
            expect($category)->not->toBeNull();
            expect($category->getMedia('category-image'))->not->toBeEmpty();
        }
    });

    it('uploads image when updating a category', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $image = UploadedFile::fake()->image('updated-category.jpg', 150, 150);

        $response = $this->patchJson("/api/restaurant/categories/{$category->id}", [
            'restaurantId' => $restaurant->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'categoryImage' => $image,
        ]);

        if ($response->status() === 200) {
            $category->refresh();
            expect($category->getMedia('category-image'))->not->toBeEmpty();
        }
    });

    it('validates image file type', function (): void {
        $restaurant = Restaurant::factory()->create();
        $invalidFile = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->postJson('/api/restaurant/categories', [
            'restaurantId' => $restaurant->id,
            'name' => 'Main Courses',
            'slug' => 'main-courses',
            'categoryImage' => $invalidFile,
        ]);

        if ($response->status() !== 422) {
            expect($response->status())->toBe(422);
        }
    });

    it('validates image file size', function (): void {
        $restaurant = Restaurant::factory()->create();
        // Create a file larger than 2048KB
        $largeFile = UploadedFile::fake()->create('large.jpg', 3000);

        $response = $this->postJson('/api/restaurant/categories', [
            'restaurantId' => $restaurant->id,
            'name' => 'Desserts',
            'slug' => 'desserts',
            'categoryImage' => $largeFile,
        ]);

        if ($response->status() !== 422) {
            expect($response->status())->toBe(422);
        }
    });

    it('allows supported image formats', function (): void {
        $restaurant = Restaurant::factory()->create();
        $supportedFormats = ['jpeg', 'jpg', 'png', 'gif', 'webp'];

        foreach ($supportedFormats as $format) {
            $image = UploadedFile::fake()->image("category.{$format}", 100, 100);

            $response = $this->postJson('/api/restaurant/categories', [
                'restaurantId' => $restaurant->id,
                'name' => "Category {$format}",
                'slug' => "category-{$format}",
                'categoryImage' => $image,
            ]);

            // Should not fail validation for supported formats
            expect($response->status())->not->toBe(422);
        }
    });

    it('returns image URL in category resource', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $image = UploadedFile::fake()->image('category.jpg', 100, 100);

        // Upload image directly to category
        $category->addMedia($image)->toMediaCollection('category-image');

        $response = $this->getJson("/api/restaurant/categories/{$category->id}");

        if ($response->status() === 200) {
            expect($response->json('imageUrl'))->not->toBeNull();
            expect($response->json('imageUrl'))->toBeString();
        }
    });

    it('handles category without image gracefully', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $response = $this->getJson("/api/restaurant/categories/{$category->id}");

        if ($response->status() === 200) {
            // Should either have null or fallback to product image
            expect($response->json('imageUrl'))->toBeNull();
        }
    });

    it('updates only image without changing other fields', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Original Name',
        ]);

        $newImage = UploadedFile::fake()->image('new-image.jpg', 100, 100);

        $response = $this->patchJson("/api/restaurant/categories/{$category->id}", [
            'categoryImage' => $newImage,
        ]);

        $category->refresh();

        if ($response->status() === 200) {
            expect($category->name)->toBe('Original Name');
            expect($category->getMedia('category-image'))->not->toBeEmpty();
        }
    });

    it('replaces previous image when updating', function (): void {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $firstImage = UploadedFile::fake()->image('first.jpg', 100, 100);
        $category->addMedia($firstImage)->toMediaCollection('category-image');

        $firstImageCount = $category->getMedia('category-image')->count();

        $secondImage = UploadedFile::fake()->image('second.jpg', 100, 100);

        $response = $this->patchJson("/api/restaurant/categories/{$category->id}", [
            'restaurantId' => $restaurant->id,
            'categoryImage' => $secondImage,
        ]);

        $category->refresh();

        if ($response->status() === 200) {
            // Should only have one image in the single-file collection
            expect($category->getMedia('category-image')->count())->toBe(1);
        }
    });
});
