<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Support;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Modules\Cleaning\Services\DepositService;
use Throwable;

/**
 * Reusable admin financial actions for a cleaning worker. Used both as table
 * row actions and as view-page header actions (Filament v5 unified actions).
 */
final class WorkerDepositActions
{
    /**
     * @return array<int, Action|ActionGroup>
     */
    public static function make(): array
    {
        return [
            ActionGroup::make([
                self::deposit(),
                self::settlement(),
                self::refund(),
                self::adjustment(),
                self::reactivate(),
            ])
                ->label(__('cleaning_admin.workers.finance.group'))
                ->icon('heroicon-o-banknotes')
                ->button(),
        ];
    }

    private static function deposit(): Action
    {
        return Action::make('recordDeposit')
            ->label(__('cleaning_admin.workers.finance.deposit.label'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->form(self::amountForm())
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn (DepositService $service) => $service->recordDeposit(
                        $record,
                        (float) $data['amount'],
                        'admin_deposit',
                        self::composeNotes($data),
                        auth()->id(),
                    ),
                    __('cleaning_admin.workers.finance.deposit.success'),
                );
            });
    }

    private static function settlement(): Action
    {
        return Action::make('recordSettlement')
            ->label(__('cleaning_admin.workers.finance.settlement.label'))
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->form(self::amountForm())
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn (DepositService $service) => $service->recordSettlement(
                        $record,
                        (float) $data['amount'],
                        'admin_settlement',
                        self::composeNotes($data),
                        auth()->id(),
                    ),
                    __('cleaning_admin.workers.finance.settlement.success'),
                );
            });
    }

    private static function refund(): Action
    {
        return Action::make('recordRefund')
            ->label(__('cleaning_admin.workers.finance.refund.label'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->form(self::amountForm())
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn (DepositService $service) => $service->recordRefund(
                        $record,
                        (float) $data['amount'],
                        'admin_refund',
                        self::composeNotes($data),
                        auth()->id(),
                    ),
                    __('cleaning_admin.workers.finance.refund.success'),
                );
            });
    }

    private static function adjustment(): Action
    {
        return Action::make('recordAdjustment')
            ->label(__('cleaning_admin.workers.finance.adjustment.label'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->form([
                TextInput::make('amount')
                    ->label(__('cleaning_admin.workers.finance.fields.signed_amount'))
                    ->helperText(__('cleaning_admin.workers.finance.hints.signed_amount'))
                    ->numeric()
                    ->required(),
                DatePicker::make('date')
                    ->label(__('cleaning_admin.workers.finance.fields.date'))
                    ->native(false)
                    ->default(now()),
                Textarea::make('notes')
                    ->label(__('cleaning_admin.workers.finance.fields.notes'))
                    ->maxLength(1000),
            ])
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn (DepositService $service) => $service->recordAdjustment(
                        $record,
                        (float) $data['amount'],
                        'admin_adjustment',
                        self::composeNotes($data),
                        auth()->id(),
                    ),
                    __('cleaning_admin.workers.finance.adjustment.success'),
                );
            });
    }

    private static function reactivate(): Action
    {
        return Action::make('reactivateAccount')
            ->label(__('cleaning_admin.workers.finance.reactivate.label'))
            ->icon('heroicon-o-lock-open')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Worker $record): bool => app(DepositService::class)->resolveAccountStatus($record) !== 'active')
            ->action(function (Worker $record): void {
                $record->update(['is_active' => true, 'is_suspended' => false]);
                app(DepositService::class)->syncEligibilityStatus($record->fresh(['deposit']) ?? $record);

                Notification::make()
                    ->title(__('cleaning_admin.workers.finance.reactivate.success'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, \Filament\Forms\Components\Field>
     */
    private static function amountForm(): array
    {
        return [
            TextInput::make('amount')
                ->label(__('cleaning_admin.workers.finance.fields.amount'))
                ->numeric()
                ->minValue(0.01)
                ->required(),
            DatePicker::make('date')
                ->label(__('cleaning_admin.workers.finance.fields.date'))
                ->native(false)
                ->default(now()),
            Textarea::make('notes')
                ->label(__('cleaning_admin.workers.finance.fields.notes'))
                ->maxLength(1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function composeNotes(array $data): ?string
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';
        $date = $data['date'] ?? null;

        if ($date) {
            $datePart = __('cleaning_admin.workers.finance.fields.date').': '.\Illuminate\Support\Carbon::parse($date)->format('Y-m-d');
            $notes = $notes === '' ? $datePart : $notes.' — '.$datePart;
        }

        return $notes === '' ? null : $notes;
    }

    /**
     * @param  callable(DepositService): mixed  $callback
     */
    private static function run(callable $callback, string $successMessage): void
    {
        try {
            $callback(app(DepositService::class));

            Notification::make()
                ->title($successMessage)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('cleaning_admin.workers.finance.error'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
