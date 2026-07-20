<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Support;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\DepositService;
use Throwable;

final class WorkerDepositActions
{
    public static function make(): array
    {
        return [
            ActionGroup::make([
                self::deposit(),
                self::debt(),
                self::settleFullDebt(),
                self::refund(),
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
            ->modalDescription(app()->isLocale('ar')
                ? 'إذا كان لدى العامل مديونية، يخصص النظام مبلغ الإيداع لتسويتها أولاً ثم يضيف المتبقي إلى رصيد الإيداع.'
                : 'If the worker has debt, the deposit settles it first and only the remainder becomes available deposit.')
            ->form(self::amountForm())
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn () => app(AdminCleaningTransactionService::class)->create($record, 'deposit', (float) $data['amount'], self::composeNotes($data), auth()->id()),
                    __('cleaning_admin.workers.finance.deposit.success'),
                );
            });
    }

    private static function debt(): Action
    {
        return Action::make('recordDebt')
            ->label(__('cleaning_finance.debt.label'))
            ->icon('heroicon-o-plus-circle')
            ->color('warning')
            ->modalDescription(app()->isLocale('ar')
                ? 'تخصم المديونية من رصيد الإيداع أولاً، ولا يظهر دين فعلي إلا بعد نفاد الإيداع.'
                : 'The charge consumes the deposit first. Actual debt is created only after the deposit reaches zero.')
            ->requiresConfirmation()
            ->form(self::amountForm(__('cleaning_finance.fields.positive_amount_hint'), notesRequired: true))
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn () => app(AdminCleaningTransactionService::class)->create($record, 'debt', (float) $data['amount'], self::composeNotes($data), auth()->id()),
                    __('cleaning_finance.debt.success'),
                );
            });
    }

    private static function settleFullDebt(): Action
    {
        return Action::make('settleFullDebt')
            ->label(app()->isLocale('ar') ? 'تصفير المديونية' : 'Settle full debt')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->visible(fn (Worker $record): bool => (float) (app(AdminCleaningTransactionService::class)->snapshot($record)['outstandingAdministrationDue'] ?? 0) > 0)
            ->requiresConfirmation()
            ->modalHeading(app()->isLocale('ar') ? 'تسوية كامل المديونية' : 'Settle the full debt')
            ->modalDescription(function (Worker $record): string {
                $amount = (float) (app(AdminCleaningTransactionService::class)->snapshot($record)['outstandingAdministrationDue'] ?? 0);

                return app()->isLocale('ar')
                    ? 'سيقوم النظام بتسجيل تسوية بقيمة '.number_format($amount, 2).' '.config('app.currency', 'SYP').' وتصفير المديونية.'
                    : 'The system will record a settlement of '.number_format($amount, 2).' '.config('app.currency', 'SYP').' and clear the debt.';
            })
            ->form([
                Textarea::make('notes')
                    ->label(__('cleaning_admin.workers.finance.fields.notes'))
                    ->maxLength(1000),
            ])
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn () => app(AdminCleaningTransactionService::class)->settleFullDebt($record, isset($data['notes']) ? mb_trim((string) $data['notes']) : null, auth()->id()),
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
            ->visible(function (Worker $record): bool {
                $snapshot = app(AdminCleaningTransactionService::class)->snapshot($record);

                return (float) ($snapshot['depositBalance'] ?? 0) > 0
                    || (float) ($snapshot['adminCommissionBalance'] ?? 0) > 0;
            })
            ->requiresConfirmation()
            ->modalHeading(app()->isLocale('ar') ? 'تصفير الحساب المالي' : 'Close the financial account')
            ->modalDescription(function (Worker $record): string {
                $snapshot = app(AdminCleaningTransactionService::class)->snapshot($record);
                $deposit = number_format((float) ($snapshot['depositBalance'] ?? 0), 2);
                $commission = number_format((float) ($snapshot['adminCommissionBalance'] ?? 0), 2);
                $currency = config('app.currency', 'SYP');

                return app()->isLocale('ar')
                    ? "سيتم استرداد كامل رصيد الإيداع ({$deposit} {$currency}) وتحويل عمولة الإدارة ({$commission} {$currency}) إلى إيرادات الإدارة المسحوبة، ثم يصبح الرصيدان صفراً. لا يمكن تنفيذ العملية مع وجود مديونية أو عمولات محجوزة لطلبات نشطة."
                    : "The full deposit balance ({$deposit} {$currency}) will be refunded and the administration commission ({$commission} {$currency}) will be moved to withdrawn administration revenue. Both balances will then be zero. The action is blocked while debt or active reserved commission exists.";
            })
            ->form(self::notesForm())
            ->action(function (Worker $record, array $data): void {
                self::run(
                    fn () => app(AdminCleaningTransactionService::class)->refundFullBalance($record, self::composeNotes($data), auth()->id()),
                    __('cleaning_admin.workers.finance.refund.success'),
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

                Notification::make()->title(__('cleaning_admin.workers.finance.reactivate.success'))->success()->send();
            });
    }

    private static function amountForm(?string $helperText = null, bool $notesRequired = false): array
    {
        return [
            TextInput::make('amount')
                ->label(__('cleaning_admin.workers.finance.fields.amount'))
                ->helperText($helperText)
                ->numeric()
                ->minValue(0.01)
                ->required(),
            ...self::notesForm($notesRequired),
        ];
    }

    private static function notesForm(bool $notesRequired = false): array
    {
        return [
            DatePicker::make('date')
                ->label(__('cleaning_admin.workers.finance.fields.date'))
                ->native(false)
                ->default(now()),
            Textarea::make('notes')
                ->label(__('cleaning_admin.workers.finance.fields.notes'))
                ->required($notesRequired)
                ->maxLength(1000),
        ];
    }

    private static function composeNotes(array $data): ?string
    {
        $notes = isset($data['notes']) ? mb_trim((string) $data['notes']) : '';
        $date = $data['date'] ?? null;

        if ($date) {
            $datePart = __('cleaning_admin.workers.finance.fields.date').': '.\Illuminate\Support\Carbon::parse($date)->format('Y-m-d');
            $notes = $notes === '' ? $datePart : $notes.' — '.$datePart;
        }

        return $notes === '' ? null : $notes;
    }

    private static function run(callable $callback, string $successMessage): void
    {
        try {
            $callback();
            Notification::make()->title($successMessage)->success()->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('cleaning_admin.workers.finance.error'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
