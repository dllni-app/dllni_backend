<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Cleaning\Models\CleaningBanner;

final class CleaningBannerSeeder extends Seeder
{
    private const IMAGE_PATH = 'cleaning-banners/cleaning-hero-banner.png';

    public function run(): void
    {
        Storage::disk('public')->put(
            self::IMAGE_PATH,
            file_get_contents(__DIR__.'/cleaning_hero_banner.png'),
        );

        CleaningBanner::query()->firstOrCreate(
            ['image_path' => self::IMAGE_PATH],
            [
                'title' => 'تنظيف يريح بالك',
                'subtitle' => 'بيت مرتب بوقت يناسبك',
                'sort_order' => 0,
                'is_active' => true,
            ],
        );

        CleaningBanner::query()
            ->where('image_path', 'cleaning-banners/cleaning-home-banner.svg')
            ->update(['is_active' => false]);
    }
}
