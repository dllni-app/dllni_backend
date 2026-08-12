<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Pages;

use App\Filament\Resources\CleaningBanners\CleaningBannerResource;
use App\Filament\Resources\CleaningBanners\Schemas\CleaningBannerForm;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBanner;

final class ListCleaningBanners extends ListRecords
{
    protected static string $resource = CleaningBannerResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.cleaning_banners.nav_label');
    }

    public function getSubheading(): ?string
    {
        return app()->isLocale('ar')
            ? 'أضف البنرات، احذفها، أو غيّر ترتيب ظهورها في التطبيق بالسحب والإفلات.'
            : 'Add banners, delete them, or drag and drop to change their display order in the app.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(app()->isLocale('ar') ? 'إضافة بانر' : 'Add Banner')
                ->modalHeading(app()->isLocale('ar') ? 'إضافة بانر تنظيف' : 'Add Cleaning Banner')
                ->modalSubmitActionLabel(app()->isLocale('ar') ? 'إضافة' : 'Add')
                ->schema(CleaningBannerForm::components())
                ->createAnother(false)
                ->using(function (array $data): CleaningBanner {
                    return DB::transaction(function () use ($data): CleaningBanner {
                        $nextSortOrder = ((int) (CleaningBanner::query()->max('sort_order') ?? -1)) + 1;

                        return CleaningBanner::query()->create(array_merge($data, [
                            'title' => '',
                            'subtitle' => null,
                            'target_url' => null,
                            'sort_order' => $nextSortOrder,
                            'starts_at' => null,
                            'ends_at' => null,
                            'is_active' => true,
                        ]));
                    });
                })
                ->successNotificationTitle(app()->isLocale('ar')
                    ? 'تمت إضافة بانر التنظيف بنجاح'
                    : 'Cleaning banner added successfully'),
        ];
    }
}
