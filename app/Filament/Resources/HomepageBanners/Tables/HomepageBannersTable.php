<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Tables;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\User\Models\MarketingOffer;

final class HomepageBannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                    ->getStateUsing(fn (MarketingOffer $record): ?string => $record->getFirstMediaUrl(MarketingOffer::IMAGE_COLLECTION) ?: null),
                TextColumn::make('created_at')
                    ->label(app()->isLocale('ar') ? 'تاريخ الإضافة' : 'Created At')
                    ->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order', HomepageBannerResource::canReorderBanners())
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
                    ->modalHeading(app()->isLocale('ar') ? 'حذف البانر' : 'Delete Banner')
                    ->modalDescription(app()->isLocale('ar')
                        ? 'سيتم حذف البانر من الصفحة الرئيسية لتطبيق المستخدم.'
                        : 'This banner will be removed from the user app homepage.'),
            ])
            ->emptyStateHeading(app()->isLocale('ar')
                ? 'لا توجد بنرات للصفحة الرئيسية'
                : 'No homepage banners')
            ->emptyStateDescription(app()->isLocale('ar')
                ? 'اضغط «إضافة بانر» لإضافة أول صورة.'
                : 'Click “Add Banner” to add the first image.')
            ->modifyQueryUsing(fn ($query) => $query->orderBy('sort_order')->orderBy('id'));
    }
}
