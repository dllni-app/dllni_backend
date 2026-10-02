<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningSpecialServices\CleaningSpecialServiceResource;
use App\Filament\Support\AdminNavigationGroup;
use Filament\Facades\Filament;

it('keeps admin navigation inside the five Arabic business sections in the configured order', function (): void {
    app()->setLocale('ar');

    $expectedGroups = ['التوصيل', 'المطاعم', 'السوبرماركت', 'التنظيفات', 'الأقسام العامة'];
    $configuredGroups = [
        AdminNavigationGroup::delivery(),
        AdminNavigationGroup::restaurants(),
        AdminNavigationGroup::supermarkets(),
        AdminNavigationGroup::cleaning(),
        AdminNavigationGroup::general(),
    ];

    expect($configuredGroups)->toBe($expectedGroups)
        ->and(Filament::getPanel('admin')->getResources())
        ->not->toBeEmpty();

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        expect(in_array($resource::getNavigationGroup(), $expectedGroups, true))->toBeTrue()
            ->and(preg_match('/\p{Arabic}/u', $resource::getNavigationLabel()))->toBe(1)
            ->and(preg_match('/\p{Arabic}/u', $resource::getModelLabel()))->toBe(1)
            ->and(preg_match('/\p{Arabic}/u', $resource::getPluralModelLabel()))->toBe(1);
    }

    foreach (Filament::getPanel('admin')->getPages() as $page) {
        if (method_exists($page, 'shouldRegisterNavigation') && ! $page::shouldRegisterNavigation()) {
            continue;
        }

        expect(method_exists($page, 'getNavigationGroup'))->toBeTrue();

        $group = $page::getNavigationGroup();

        expect($group)->not->toBeNull()
            ->and(in_array($group, $expectedGroups, true))->toBeTrue()
            ->and(preg_match('/\p{Arabic}/u', $page::getNavigationLabel()))->toBe(1);
    }
});

it('keeps cleaning special services Arabic across its navigation and field choices', function (): void {
    app()->setLocale('ar');

    expect(CleaningSpecialServiceResource::getNavigationLabel())->toBe('الخدمات الخاصة')
        ->and(CleaningSpecialServiceResource::getModelLabel())->toBe('خدمة تنظيف خاصة')
        ->and(__('cleaning_special_services.fields.category'))->toBe('الفئة')
        ->and(__('cleaning_special_services.pricing_units.sqm'))->toBe('متر مربع')
        ->and(__('cleaning_special_services.pay_modes.per_km'))->toBe('لكل كيلومتر');
});

it('maps cleaning catalog states to Arabic presentation labels without changing stored keys', function (): void {
    app()->setLocale('ar');

    expect(__('cleaning_catalog.materials.statuses.pending'))->toBe('قيد الانتظار')
        ->and(__('cleaning_catalog.materials.statuses.in_progress'))->toBe('قيد التنفيذ')
        ->and(__('cleaning_catalog.materials.statuses.completed'))->toBe('مكتمل')
        ->and(__('cleaning_catalog.materials.options.deep'))->toBe('عميق');
});
