<?php

declare(strict_types=1);

use App\Filament\Pages\CleaningOverview;
use App\Filament\Pages\DeliveryOperationsHub;
use App\Filament\Pages\PlatformOperationsDashboard;
use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Resources\CleaningSpecialServices\CleaningSpecialServiceResource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the five admin business sections and the localized cleaning special services page', function (): void {
    $this->seed(DatabaseSeeder::class);
    app()->setLocale('ar');

    $admin = User::query()->where('email', 'admin@admin.com')->firstOrFail();
    $this->actingAs($admin);

    $pages = [
        'delivery' => DeliveryOperationsHub::getUrl(panel: 'admin'),
        'restaurants' => RestaurantSectionHub::getUrl(panel: 'admin'),
        'supermarkets' => SupermarketSectionHub::getUrl(panel: 'admin'),
        'cleaning' => CleaningOverview::getUrl(panel: 'admin'),
        'general' => PlatformOperationsDashboard::getUrl(panel: 'admin'),
        'cleaning_special_services' => CleaningSpecialServiceResource::getUrl('index', panel: 'admin'),
    ];

    foreach ($pages as $name => $url) {
        $response = $this->get($url);

        $response->assertOk()
            ->assertSee('التوصيل')
            ->assertSee('المطاعم')
            ->assertSee('السوبرماركت')
            ->assertSee('التنظيفات')
            ->assertSee('الأقسام العامة');

        if ($name === 'cleaning_special_services') {
            $response
                ->assertSee('الخدمات الخاصة')
                ->assertSee('اسم الخدمة')
                ->assertSee('وحدة التسعير')
                ->assertDontSee('Cleaning Special Services')
                ->assertDontSee('Base unit price');
        }
    }
});
