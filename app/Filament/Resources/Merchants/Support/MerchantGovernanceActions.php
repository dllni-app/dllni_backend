<?php

declare(strict_types=1);

namespace App\Filament\Resources\Merchants\Support;

use App\Filament\Resources\Restaurants\RestaurantResource;
use App\Filament\Resources\SmStores\SmStoreResource;
use App\Services\MerchantGovernanceService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmStore;

final class MerchantGovernanceActions
{
    public static function restaurant(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('suspend_restaurant')
                ->label('تعليق المطعم')
                ->color('danger')
                ->visible(fn (Restaurant $record): bool => RestaurantResource::canManageGovernance() && ! ($record->suspension_until?->isFuture() ?? false))
                ->form([
                    DateTimePicker::make('suspension_until')->label('تعليق حتى')->required()->minDate(now()->addMinute()),
                    self::reason(),
                ])
                ->requiresConfirmation()
                ->action(fn (Restaurant $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->suspendRestaurant($record, (string) $data['suspension_until'], (string) $data['reason'], auth()->user()),
                    'تم تعليق المطعم'
                )),
            Action::make('unsuspend_restaurant')
                ->label('إلغاء تعليق المطعم')
                ->color('success')
                ->visible(fn (Restaurant $record): bool => RestaurantResource::canManageGovernance() && ($record->suspension_until?->isFuture() ?? false))
                ->form([self::reason()])
                ->requiresConfirmation()
                ->action(fn (Restaurant $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->unsuspendRestaurant($record, (string) $data['reason'], auth()->user()),
                    'تم إلغاء التعليق'
                )),
            Action::make('adjust_restaurant_reputation')
                ->label('تعديل نقاط الثقة')
                ->visible(fn (): bool => RestaurantResource::canManageGovernance())
                ->form([
                    TextInput::make('score')->label('النقاط الجديدة')->numeric()->required()->minValue(0)->maxValue(100),
                    self::reason(),
                ])
                ->requiresConfirmation()
                ->action(fn (Restaurant $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->adjustRestaurantReputation($record, (int) $data['score'], (string) $data['reason'], auth()->user()),
                    'تم تعديل نقاط الثقة'
                )),
            Action::make('toggle_restaurant_featured')
                ->label(fn (Restaurant $record): string => $record->is_featured ? 'إلغاء تمييز المطعم' : 'تمييز المطعم')
                ->visible(fn (): bool => RestaurantResource::canManageGovernance())
                ->form([self::reason()])
                ->requiresConfirmation()
                ->action(fn (Restaurant $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->setRestaurantFeatured($record, ! $record->is_featured, (string) $data['reason'], auth()->user()),
                    'تم تحديث حالة التمييز'
                )),
            Action::make('toggle_restaurant_active')
                ->label(fn (Restaurant $record): string => $record->is_active ? 'تعطيل المطعم' : 'تفعيل المطعم')
                ->color(fn (Restaurant $record): string => $record->is_active ? 'danger' : 'success')
                ->visible(fn (): bool => RestaurantResource::canManageGovernance())
                ->form([self::reason()])
                ->requiresConfirmation()
                ->action(fn (Restaurant $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->setRestaurantActive($record, ! $record->is_active, (string) $data['reason'], auth()->user()),
                    'تم تحديث حالة المطعم'
                )),
        ])->label('إجراءات الإدارة')->icon('heroicon-o-shield-check')->button();
    }

    public static function store(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('suspend_store')
                ->label('تعليق المتجر')
                ->color('danger')
                ->visible(fn (SmStore $record): bool => SmStoreResource::canManageGovernance() && ! ($record->suspension_until?->isFuture() ?? false))
                ->form([
                    DateTimePicker::make('suspension_until')->label('تعليق حتى')->required()->minDate(now()->addMinute()),
                    self::reason(),
                ])
                ->requiresConfirmation()
                ->action(fn (SmStore $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->suspendStore($record, (string) $data['suspension_until'], (string) $data['reason'], auth()->user()),
                    'تم تعليق المتجر'
                )),
            Action::make('unsuspend_store')
                ->label('إلغاء تعليق المتجر')
                ->color('success')
                ->visible(fn (SmStore $record): bool => SmStoreResource::canManageGovernance() && ($record->suspension_until?->isFuture() ?? false))
                ->form([self::reason()])
                ->requiresConfirmation()
                ->action(fn (SmStore $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->unsuspendStore($record, (string) $data['reason'], auth()->user()),
                    'تم إلغاء التعليق'
                )),
            Action::make('adjust_store_trust')
                ->label('تعديل نقاط الثقة')
                ->visible(fn (): bool => SmStoreResource::canManageGovernance())
                ->form([
                    TextInput::make('score')->label('النقاط الجديدة')->numeric()->required()->minValue(0)->maxValue(100),
                    self::reason(),
                ])
                ->requiresConfirmation()
                ->action(fn (SmStore $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->adjustStoreTrust($record, (int) $data['score'], (string) $data['reason'], auth()->user()),
                    'تم تعديل نقاط الثقة'
                )),
            Action::make('toggle_store_featured')
                ->label(fn (SmStore $record): string => $record->is_featured ? 'إلغاء تمييز المتجر' : 'تمييز المتجر')
                ->visible(fn (): bool => SmStoreResource::canManageGovernance())
                ->form([self::reason()])
                ->requiresConfirmation()
                ->action(fn (SmStore $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->setStoreFeatured($record, ! $record->is_featured, (string) $data['reason'], auth()->user()),
                    'تم تحديث حالة التمييز'
                )),
            Action::make('toggle_store_active')
                ->label(fn (SmStore $record): string => $record->is_active ? 'تعطيل المتجر' : 'تفعيل المتجر')
                ->color(fn (SmStore $record): string => $record->is_active ? 'danger' : 'success')
                ->visible(fn (): bool => SmStoreResource::canManageGovernance())
                ->form([self::reason()])
                ->requiresConfirmation()
                ->action(fn (SmStore $record, array $data) => self::run(
                    fn () => app(MerchantGovernanceService::class)->setStoreActive($record, ! $record->is_active, (string) $data['reason'], auth()->user()),
                    'تم تحديث حالة المتجر'
                )),
        ])->label('إجراءات الإدارة')->icon('heroicon-o-shield-check')->button();
    }

    private static function reason(): Textarea
    {
        return Textarea::make('reason')->label('سبب الإجراء')->required()->maxLength(1000);
    }

    private static function run(callable $callback, string $message): void
    {
        $callback();
        Notification::make()->title($message)->success()->send();
    }
}
