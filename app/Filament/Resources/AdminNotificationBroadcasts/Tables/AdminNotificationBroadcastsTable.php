<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminNotificationBroadcasts\Tables;

use App\Enums\UserModuleType;
use App\Models\AdminNotificationBroadcast;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AdminNotificationBroadcastsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->limit(40),
                TextColumn::make('body')->label('النص')->limit(50)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('audience_type')->label('المستلمون')->badge()
                    ->formatStateUsing(fn (string $state): string => self::audienceLabel($state)),
                TextColumn::make('module_types')->label('الفئات')
                    ->formatStateUsing(fn (?array $state): string => self::moduleTypesLabel($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('recipients_count')->label('عدد المستلمين')->sortable(),
                TextColumn::make('creator.name')->label('أرسل بواسطة')->placeholder('-'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('لا توجد إشعارات مرسلة')
            ->emptyStateDescription('أرسل إشعاراً جديداً للمستخدمين أو فئة منهم.');
    }

    private static function audienceLabel(string $state): string
    {
        return match ($state) {
            AdminNotificationBroadcast::AUDIENCE_ALL => 'جميع المستخدمين',
            AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES => 'فئات محددة',
            AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS => 'مستخدمون محددون',
            default => $state,
        };
    }

    /** @param  array<int, string>|null  $state */
    private static function moduleTypesLabel(?array $state): string
    {
        if ($state === null || $state === []) {
            return '-';
        }

        $labels = [
            'customer' => 'المستخدمون',
            UserModuleType::RestaurantSeller->value => 'المطاعم',
            UserModuleType::SupermarketSeller->value => 'السوبر ماركت',
            UserModuleType::CleaningWorker->value => 'التنظيف',
            UserModuleType::DeliveryDriver->value => 'التوصيل',
        ];

        return implode('، ', array_map(fn (string $type): string => $labels[$type] ?? $type, $state));
    }
}
