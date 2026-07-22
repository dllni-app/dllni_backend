<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemUsers\Tables;

use App\Enums\UserModuleType;
use App\Filament\Support\ArabicDashboardLabels;
use App\Models\User;
use App\Services\UserAccountStatusService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SystemUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder('البحث بالاسم أو رقم الهاتف أو البريد الإلكتروني')
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_type')
                    ->label('نوع الحساب')
                    ->badge()
                    ->state(fn (User $record): string => self::accountTypeLabel($record))
                    ->color(fn (User $record): string => self::accountTypeColor($record)),
                TextColumn::make('is_active')
                    ->label('حالة الحساب')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'فعال' : 'غير فعال')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextColumn::make('created_at')
                    ->label('تاريخ التسجيل')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('roles'))
            ->filters([
                SelectFilter::make('module_type')
                    ->label('نوع الحساب')
                    ->options([
                        UserModuleType::CleaningWorker->value => 'عامل تنظيف',
                        UserModuleType::RestaurantSeller->value => 'بائع مطعم',
                        UserModuleType::SupermarketSeller->value => 'بائع سوبرماركت',
                        UserModuleType::DeliveryDriver->value => 'مندوب توصيل',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('حالة الحساب')
                    ->trueLabel('الحسابات الفعالة')
                    ->falseLabel('الحسابات غير الفعالة')
                    ->placeholder('جميع الحسابات'),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                Action::make('deactivate')
                    ->label('إلغاء تفعيل الحساب')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => "إلغاء تفعيل حساب {$record->name}")
                    ->modalDescription('سيتم منع المستخدم من تسجيل الدخول وإلغاء جميع رموز الوصول الحالية. يمكن إعادة تفعيل الحساب لاحقاً.')
                    ->modalSubmitActionLabel('إلغاء التفعيل')
                    ->action(function (User $record): void {
                        app(UserAccountStatusService::class)->deactivate($record);

                        Notification::make()
                            ->title('تم إلغاء تفعيل الحساب')
                            ->body('لم يعد بإمكان المستخدم تسجيل الدخول أو استخدام رموز الوصول السابقة.')
                            ->success()
                            ->send();
                    }),
                Action::make('activate')
                    ->label('إعادة تفعيل الحساب')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => "إعادة تفعيل حساب {$record->name}")
                    ->modalDescription('سيتم السماح للمستخدم بتسجيل الدخول إلى النظام من جديد.')
                    ->modalSubmitActionLabel('إعادة التفعيل')
                    ->action(function (User $record): void {
                        app(UserAccountStatusService::class)->activate($record);

                        Notification::make()
                            ->title('تمت إعادة تفعيل الحساب')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('لا توجد حسابات مستخدمين')
            ->emptyStateDescription('ستظهر حسابات مستخدمي التطبيقات هنا بعد التسجيل.');
    }

    public static function accountTypeLabel(User $record): string
    {
        return match ($record->module_type) {
            UserModuleType::CleaningWorker => 'عامل تنظيف',
            UserModuleType::RestaurantSeller => 'بائع مطعم',
            UserModuleType::SupermarketSeller => 'بائع سوبرماركت',
            UserModuleType::DeliveryDriver => 'مندوب توصيل',
            default => $record->roles->isNotEmpty()
                ? ArabicDashboardLabels::roleName($record->roles->first()?->name)
                : 'عميل',
        };
    }

    public static function accountTypeColor(User $record): string
    {
        return match ($record->module_type) {
            UserModuleType::CleaningWorker => 'info',
            UserModuleType::RestaurantSeller => 'warning',
            UserModuleType::SupermarketSeller => 'success',
            UserModuleType::DeliveryDriver => 'primary',
            default => 'gray',
        };
    }
}
