<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Tables;

use App\Filament\Resources\CleaningWorkers\Support\WorkerDepositActions;
use App\Filament\Resources\Workers\Support\WorkerSuspensionActions;
use App\Filament\Support\ArabicDashboardLabels;
use App\Models\Worker;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;

final class CleaningWorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder('ابحث باسم العامل، المستخدم أو رقم الهاتف')
            ->columns([
                TextColumn::make('id')->label(__('cleaning_admin.workers.fields.id'))->sortable(),
                TextColumn::make('first_name')->label(__('cleaning_admin.workers.fields.first_name'))->searchable()->wrap(),
                TextColumn::make('user.name')->label(__('cleaning_admin.workers.fields.user_name'))->searchable()->wrap(),
                TextColumn::make('user.phone')->label(__('cleaning_admin.workers.fields.phone'))->copyable(),
                TextColumn::make('gender')
                    ->label(__('cleaning_admin.workers.fields.gender'))
                    ->formatStateUsing(fn (?string $state): string => self::genderLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::genderColor($state))
                    ->sortable(),
                TextColumn::make('trust_score')->label(__('cleaning_admin.workers.fields.trust_score'))->sortable(),
                TextColumn::make('average_rating')->label(__('cleaning_admin.workers.fields.average_rating'))->sortable()->toggleable(),
                TextColumn::make('total_completed_jobs')->label(__('cleaning_admin.workers.fields.total_completed_jobs'))->sortable()->toggleable(),
                TextColumn::make('deposit.current_balance')
                    ->label('رصيد الإيداع')
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money(max(0, (float) ($state ?? 0))))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('deposit.debt_balance')
                    ->label('المديونية الحالية')
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money(max(0, (float) ($state ?? 0))))
                    ->badge()
                    ->color(fn ($state): string => (float) ($state ?? 0) > 0 ? 'danger' : 'success')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('deposit.max_negative_balance')
                    ->label('حد المديونية المسموح')
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money(max(0, (float) ($state ?? 0))))
                    ->placeholder('0.00 ل.س')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remaining_debt_capacity')
                    ->label('سعة المديونية المتبقية')
                    ->state(fn (Worker $record): float => self::capacity($record)['remainingDebtCapacity'])
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                    ->badge()
                    ->color(fn (float $state): string => $state > 0 ? 'success' : 'danger')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('available_commission_capacity')
                    ->label('السعة المالية للطلبات')
                    ->state(fn (Worker $record): float => self::capacity($record)['availableCommissionCapacity'])
                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                    ->badge()
                    ->color(fn (float $state): string => $state > 0 ? 'success' : 'danger')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('security_deposit_status')
                    ->label('حالة الحساب المالي')
                    ->formatStateUsing(fn (?string $state): string => self::depositStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::depositStatusColor($state)),
                IconColumn::make('is_suspended')
                    ->label(__('cleaning_admin.workers.fields.suspended'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('gender')
                    ->label(__('cleaning_admin.workers.fields.gender'))
                    ->options([
                        'male' => __('cleaning_admin.workers.gender_options.male'),
                        'female' => __('cleaning_admin.workers.gender_options.female'),
                    ]),
                TernaryFilter::make('is_suspended')->label(__('cleaning_admin.workers.fields.suspended')),
                SelectFilter::make('security_deposit_status')
                    ->label('حالة الحساب المالي')
                    ->options([
                        'active' => 'نشط',
                        'insufficient_balance' => 'السعة المالية غير كافية',
                        'suspended' => 'موقوف',
                    ]),
                Filter::make('has_debt')
                    ->label('لديه مديونية')
                    ->query(fn (Builder $query): Builder => $query->whereHas('deposit', fn (Builder $deposit): Builder => $deposit->where('debt_balance', '>', 0))),
                Filter::make('blocked_by_balance')
                    ->label('محجوب بسبب السعة المالية')
                    ->query(fn (Builder $query): Builder => $query->where('security_deposit_status', 'insufficient_balance')),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                ...WorkerSuspensionActions::make(),
                EditAction::make()->label('تعديل'),
                ...WorkerDepositActions::make(),
            ]);
    }

    /** @return array<string, float> */
    private static function capacity(Worker $worker): array
    {
        return app(WorkerOrderSolvencyService::class)->workerCapacitySummary($worker);
    }

    private static function genderLabel(?string $gender): string
    {
        return match ($gender) {
            'male' => __('cleaning_admin.workers.gender_options.male'),
            'female' => __('cleaning_admin.workers.gender_options.female'),
            default => '-',
        };
    }

    private static function genderColor(?string $gender): string
    {
        return match ($gender) {
            'male' => 'info',
            'female' => 'warning',
            default => 'gray',
        };
    }

    private static function depositStatusLabel(?string $status): string
    {
        return $status === 'insufficient_balance'
            ? 'السعة المالية غير كافية'
            : ArabicDashboardLabels::depositStatus($status);
    }

    private static function depositStatusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'insufficient_balance' => 'danger',
            'suspended' => 'warning',
            default => 'gray',
        };
    }
}
