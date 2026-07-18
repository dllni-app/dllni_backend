<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes\Tables;

use App\Filament\Resources\CleaningHomeTypes\CleaningHomeTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningHomeType;

final class CleaningHomeTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('الصورة')
                    ->square()
                    ->getStateUsing(fn (CleaningHomeType $record): ?string => $record->imageUrl()),
                TextColumn::make('title')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('الرمز')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('section')
                    ->label('القسم')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CleaningHomeType::SECTION_PROPERTY => 'التنظيفات',
                        CleaningHomeType::SECTION_OCCASION => 'المناسبات',
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === CleaningHomeType::SECTION_OCCASION ? 'warning' : 'info')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('الترتيب')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('ظاهر')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->searchPlaceholder('ابحث بالاسم أو رمز النوع')
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('section')
                    ->label('القسم')
                    ->options([
                        CleaningHomeType::SECTION_PROPERTY => 'التنظيفات',
                        CleaningHomeType::SECTION_OCCASION => 'المناسبات',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('ظاهر في التطبيق'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('تعديل')
                    ->url(fn (CleaningHomeType $record): string => CleaningHomeTypeResource::getUrl('edit', ['record' => $record])),
                DeleteAction::make()
                    ->label('حذف')
                    ->requiresConfirmation()
                    ->modalDescription('سيختفي النوع من التطبيق، لكن سيبقى رمز النوع محفوظاً لدعم الطلبات السابقة.'),
            ])
            ->emptyStateHeading('لا توجد أنواع لواجهة التنظيف')
            ->emptyStateDescription('أضف أنواع العقارات أو المناسبات التي يجب أن تظهر في تطبيق المستخدم.')
            ->modifyQueryUsing(fn ($query) => $query->orderBy('section')->orderBy('sort_order')->orderBy('id'));
    }
}
