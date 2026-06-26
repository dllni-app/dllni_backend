<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Tables;

use App\Filament\Resources\CleaningWorkers\Support\WorkerDepositActions;
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
            ->columns([
                TextColumn::make('id')->label(__('cleaning_admin.workers.fields.id'))->sortable(),
                TextColumn::make('first_name')->label(__('cleaning_admin.workers.fields.first_name'))->searchable(),
                TextColumn::make('user.name')->label(__('cleaning_admin.workers.fields.user_name'))->searchable(),
                TextColumn::make('user.phone')->label(__('cleaning_admin.workers.fields.phone')),
                TextColumn::make('trust_score')->label(__('cleaning_admin.workers.fields.trust_score'))->sortable(),
                TextColumn::make('average_rating')->label(__('cleaning_admin.workers.fields.average_rating'))->sortable(),
                TextColumn::make('total_completed_jobs')->label(__('cleaning_admin.workers.fields.total_completed_jobs'))->sortable(),
                TextColumn::make('deposit.current_balance')
                    ->label('Current balance')
                    ->money('SYP')
                    ->sortable(),
                TextColumn::make('deposit.max_negative_balance')
                    ->label('Allowed debt limit')
                    ->money('SYP')
                    ->placeholder('SYP 0.00')
                    ->toggleable(),
                TextColumn::make('current_debt_amount')
                    ->label('Current debt')
                    ->state(fn (Worker $record): float => self::capacity($record)['currentDebtAmount'])
                    ->money('SYP')
                    ->badge()
                    ->color(fn (float $state): string => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('available_commission_capacity')
                    ->label('Commission capacity')
                    ->state(fn (Worker $record): float => self::capacity($record)['availableCommissionCapacity'])
                    ->money('SYP')
                    ->badge()
                    ->color(fn (float $state): string => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('security_deposit_status')
                    ->label('Deposit status')
                    ->formatStateUsing(fn (?string $state): string => self::depositStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::depositStatusColor($state)),
                IconColumn::make('is_active')->label(__('cleaning_admin.workers.fields.is_active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('cleaning_admin.workers.fields.is_active')),
                TernaryFilter::make('is_suspended')->label(__('cleaning_admin.workers.fields.suspended')),
                SelectFilter::make('security_deposit_status')
                    ->label('Deposit status')
                    ->options([
                        'active' => 'Active',
                        'insufficient_balance' => 'Insufficient balance',
                        'missing_deposit' => 'Missing deposit',
                        'suspended' => 'Suspended',
                    ]),
                Filter::make('has_debt')
                    ->label('Has debt')
                    ->query(fn (Builder $query): Builder => $query->whereHas('deposit', fn (Builder $deposit): Builder => $deposit->where('current_balance', '<', 0))),
                Filter::make('blocked_by_balance')
                    ->label('Blocked by balance')
                    ->query(fn (Builder $query): Builder => $query->where('security_deposit_status', 'insufficient_balance')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ...WorkerDepositActions::make(),
            ]);
    }

    /** @return array<string, float> */
    private static function capacity(Worker $worker): array
    {
        return app(WorkerOrderSolvencyService::class)->workerCapacitySummary($worker);
    }

    private static function depositStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'insufficient_balance' => 'Insufficient balance',
            'missing_deposit' => 'Missing deposit',
            'suspended' => 'Suspended',
            default => 'Unknown',
        };
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
