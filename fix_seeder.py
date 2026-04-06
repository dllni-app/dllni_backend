#!/usr/bin/env python3
"""Fix the RestaurantSeeder.php file"""

file_path = r'Modules/Resturants/database/seeders/RestaurantSeeder.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Simple substring replacement
old = '''        foreach ($products as $product) {
            // Add 2-3 additional images per product to the 'images' collection
            for ($i = 1; $i <= fake()->numberBetween(2, 3); $i++) {
                SeederMedia::ensureSingleMedia($product, 'images', $imageUrl, $imageSeed);
            }
        }
    }
}'''

new = '''        foreach ($products as $product) {
            // Add 2-3 additional images per product to the 'images' collection
            $imageCount = fake()->numberBetween(2, 3);
            for ($i = 1; $i <= $imageCount; $i++) {
                $imageUrl = "https://picsum.photos/seed/restaurant-{$seed}-product-{$product->id}-img{$i}/600/600";
                $imageSeed = "restaurant-{$seed}-product-{$product->id}-img{$i}";

                if (! app()->runningUnitTests()) {
                    try {
                        $product->addMediaFromUrl($imageUrl)->toMediaCollection('images');
                        continue;
                    } catch (Throwable) {
                        // Continue with local fallback.
                    }
                }

                // Use placeholder for testing environment
                $tempPath = tempnam(sys_get_temp_dir(), 'seed-media-');
                if ($tempPath === false) {
                    continue;
                }

                $pngPath = $tempPath . '-' . Str::slug($imageSeed, '-') . '.png';
                @unlink($tempPath);

                $decoded = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAtMB9dZYkEYAAAAASUVORK5CYII=', true);
                if (! is_string($decoded) || $decoded === '') {
                    continue;
                }

                $bytes = file_put_contents($pngPath, $decoded);
                if ($bytes === false || $bytes === 0) {
                    @unlink($pngPath);
                    continue;
                }

                try {
                    $product->addMedia($pngPath)
                        ->usingFileName(Str::slug($imageSeed, '-') . '.png')
                        ->toMediaCollection('images');
                } catch (Throwable) {
                    // Ignore media failures in dev seed data.
                } finally {
                    if (is_file($pngPath)) {
                        @unlink($pngPath);
                    }
                }
            }
        }
    }
}'''

if old in content:
    content = content.replace(old, new)
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(content)
    print('✓ Fixed seedProductImages method')
else:
    print('✗ Could not find method to replace')
    print(f'File size: {len(content)} chars')
