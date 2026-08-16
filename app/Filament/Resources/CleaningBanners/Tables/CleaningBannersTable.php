<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Tables;

use App\Filament\Resources\CleaningBanners\CleaningBannerResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Modules\Cleaning\Models\CleaningBanner;

final class CleaningBannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                    ->getStateUsing(fn (CleaningBanner $record): ?string => $record->imageUrl()),
                TextColumn::make('created_at')
                    ->label(app()->isLocale('ar') ? 'تاريخ الإضافة' : 'Created At')
                    ->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order', CleaningBannerResource::canReorderBanners())
            ->reorderRecordsTriggerAction(
                fn (Action $action, bool $isReordering): Action => $action
                    ->button()
                    ->label($isReordering
                        ? (app()->isLocale('ar') ? 'إنهاء تغيير الترتيب' : 'Finish Reordering')
                        : (app()->isLocale('ar') ? 'تغيير الترتيب بالسحب والإفلات' : 'Reorder by Drag and Drop')),
            )
            ->recordActions([
                DeleteAction::make()
                    ->label(app()->isLocale('ar') ? 'حذف' : 'Delete')
                    ->requiresConfirmation()
                    ->modalHeading(app()->isLocale('ar') ? 'حذف بانر التنظيف' : 'Delete Cleaning Banner')
                    ->modalDescription(app()->isLocale('ar')
                        ? 'سيتم حذف البانر من قسم التنظيف في تطبيق المستخدم.'
                        : 'This banner will be removed from the cleaning section in the user app.')
                    ->before(function (CleaningBanner $record): void {
                        if (is_string($record->image_path) && $record->image_path !== '') {
                            Storage::disk('public')->delete($record->image_path);
                        }
                    }),
            ])
            ->emptyStateHeading(app()->isLocale('ar')
                ? 'لا توجد بنرات لقسم التنظيف'
                : 'No cleaning banners')
            ->emptyStateDescription(app()->isLocale('ar')
                ? 'اضغط «إضافة بانر» لإضافة أول صورة.'
                : 'Click “Add Banner” to add the first image.')
            ->modifyQueryUsing(fn ($query) => $query->orderBy('sort_order')->orderBy('id'));
    }
}
