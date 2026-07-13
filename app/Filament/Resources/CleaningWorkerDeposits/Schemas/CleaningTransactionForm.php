<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Schemas;

use App\Enums\UserModuleType;
use App\Models\Worker;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Throwable;

final class CleaningTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('cleaning_finance_guidance.form.worker_section'))
                    ->description(__('cleaning_finance_guidance.form.worker_section_description'))
                    ->columns(2)
                    ->schema([
                        Select::make('worker_id')
                            ->label(__('cleaning_admin.transactions.fields.worker'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.worker'))
                            ->options(fn (): array => self::workerOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('suggested_amount', null);
                                $set('amount', null);
                            }),
                        Select::make('type')
                            ->label(__('cleaning_admin.transactions.fields.type'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.type'))
                            ->helperText(__('cleaning_finance_guidance.select_helper'))
                            ->options([
                                'deposit' => __('cleaning_admin.transactions.types.deposit'),
                                'debt' => __('cleaning_finance.types.debt'),
                                'settlement' => __('cleaning_admin.transactions.types.settlement'),
                                'refund' => __('cleaning_admin.transactions.types.refund'),
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('suggested_amount', null);
                                $set('amount', null);
                            }),
                    ]),
                Section::make(__('cleaning_finance_guidance.form.financial_summary'))
                    ->description(__('cleaning_finance_guidance.form.financial_summary_description'))
                    ->visible(fn (Get $get): bool => self::worker($get('worker_id')) instanceof Worker)
                    ->columns([
                        'default' => 2,
                        'md' => 3,
                        'xl' => 4,
                    ])
                    ->schema([
                        self::moneyMetric('current_balance', 'currentBalance'),
                        self::moneyMetric('minimum_required', 'minimumRequired'),
                        self::moneyMetric('deposited_total', 'depositedTotal'),
                        self::moneyMetric('withdrawn_total', 'withdrawnTotal'),
                        self::moneyMetric('outstanding_due', 'outstandingAdministrationDue'),
                        self::moneyMetric('manual_debt_due', 'manualDebtDue'),
                        self::moneyMetric('admin_fee_due', 'adminFeeDue'),
                        self::moneyMetric('total_settled', 'totalSettled'),
                        self::moneyMetric('total_revenue', 'totalRevenue'),
                        self::moneyMetric('total_commission', 'totalCommission'),
                        Placeholder::make('completed_jobs_metric')
                            ->label(__('cleaning_finance_guidance.metrics.completed_jobs'))
                            ->content(fn (Get $get): string => (string) (self::snapshot($get('worker_id'))['completedJobs'] ?? 0)),
                        Placeholder::make('account_status_metric')
                            ->label(__('cleaning_finance_guidance.metrics.account_status'))
                            ->content(fn (Get $get): string => self::statusLabel((string) (self::snapshot($get('worker_id'))['status'] ?? 'unknown'))),
                    ]),
                Section::make(__('cleaning_finance_guidance.form.transaction_section'))
                    ->description(__('cleaning_finance_guidance.form.transaction_section_description'))
                    ->columns(2)
                    ->schema([
                        Select::make('suggested_amount')
                            ->label(__('cleaning_finance_guidance.fields.suggested_amount'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.suggested_amount'))
                            ->helperText(__('cleaning_finance_guidance.fields.suggested_amount_helper'))
                            ->options(fn (Get $get): array => self::suggestedAmounts($get('worker_id'), $get('type')))
                            ->visible(fn (Get $get): bool => self::suggestedAmounts($get('worker_id'), $get('type')) !== [])
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                if (is_numeric($state)) {
                                    $set('amount', (float) $state);
                                }
                            }),
                        TextInput::make('amount')
                            ->label(__('cleaning_admin.transactions.fields.amount'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.amount'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->live(debounce: 400)
                            ->helperText(fn (Get $get): string => self::amountHelper($get('worker_id'), $get('type')))
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $worker = self::worker($get('worker_id'));

                                    if (! $worker instanceof Worker) {
                                        $fail(__('cleaning_finance_guidance.validation.worker_required'));

                                        return;
                                    }

                                    if (! is_numeric($value)) {
                                        $fail(__('cleaning_finance_guidance.validation.amount_positive'));

                                        return;
                                    }

                                    $message = app(AdminCleaningTransactionService::class)->validationMessage(
                                        $worker,
                                        (string) $get('type'),
                                        (float) $value,
                                    );

                                    if ($message !== null) {
                                        $fail($message);
                                    }
                                },
                            ]),
                        Placeholder::make('amount_validation')
                            ->label(__('cleaning_finance_guidance.fields.validation_result'))
                            ->content(fn (Get $get): string => self::validationResult(
                                $get('worker_id'),
                                $get('type'),
                                $get('amount'),
                            ))
                            ->columnSpanFull(),
                        Placeholder::make('transaction_type_guide')
                            ->label(__('cleaning_finance_guidance.title'))
                            ->content(fn (Get $get): string => self::typeDescription((string) $get('type')))
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('cleaning_admin.transactions.fields.notes'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.notes'))
                            ->rows(4)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function moneyMetric(string $name, string $snapshotKey): Placeholder
    {
        return Placeholder::make($name.'_metric')
            ->label(__('cleaning_finance_guidance.metrics.'.$name))
            ->content(fn (Get $get): string => self::money((float) (self::snapshot($get('worker_id'))[$snapshotKey] ?? 0)));
    }

    /**
     * @return array<int, string>
     */
    private static function workerOptions(): array
    {
        return Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('module_type', UserModuleType::CleaningWorker))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Worker $worker): array => [
                $worker->id => ($worker->first_name ?: ('#'.$worker->id)).' (#'.$worker->id.')',
            ])
            ->all();
    }

    private static function worker(mixed $workerId): ?Worker
    {
        if (! is_numeric($workerId) || (int) $workerId <= 0) {
            return null;
        }

        try {
            return app(AdminCleaningTransactionService::class)->findWorker((int) $workerId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(mixed $workerId): array
    {
        $worker = self::worker($workerId);

        return $worker instanceof Worker
            ? app(AdminCleaningTransactionService::class)->snapshot($worker)
            : [];
    }

    /**
     * @return array<string, string>
     */
    private static function suggestedAmounts(mixed $workerId, mixed $type): array
    {
        $worker = self::worker($workerId);

        if (! $worker instanceof Worker || ! is_string($type) || $type === '') {
            return [];
        }

        return app(AdminCleaningTransactionService::class)->suggestedAmounts($worker, $type);
    }

    private static function amountHelper(mixed $workerId, mixed $type): string
    {
        $snapshot = self::snapshot($workerId);

        if ($snapshot === [] || ! is_string($type) || $type === '') {
            return __('cleaning_finance_guidance.fields.amount_helper_default');
        }

        return match ($type) {
            'deposit' => __('cleaning_finance_guidance.fields.amount_helper_deposit', [
                'gap' => self::money((float) $snapshot['depositGap']),
            ]),
            'debt' => __('cleaning_finance_guidance.fields.amount_helper_debt'),
            'settlement' => __('cleaning_finance_guidance.fields.amount_helper_settlement', [
                'due' => self::money((float) $snapshot['outstandingAdministrationDue']),
            ]),
            'refund' => __('cleaning_finance_guidance.fields.amount_helper_refund', [
                'maximum' => self::money((float) $snapshot['maxRefundable']),
            ]),
            default => __('cleaning_finance_guidance.fields.amount_helper_default'),
        };
    }

    private static function validationResult(mixed $workerId, mixed $type, mixed $amount): string
    {
        $worker = self::worker($workerId);

        if (! $worker instanceof Worker) {
            return __('cleaning_finance_guidance.validation.select_worker_first');
        }

        if (! is_string($type) || $type === '') {
            return __('cleaning_finance_guidance.validation.select_type_first');
        }

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return __('cleaning_finance_guidance.validation.enter_amount_first');
        }

        $service = app(AdminCleaningTransactionService::class);
        $message = $service->validationMessage($worker, $type, (float) $amount);

        if ($message !== null) {
            return __('cleaning_finance_guidance.validation.invalid_prefix', ['message' => $message]);
        }

        $projectedBalance = $service->projectedBalance($worker, $type, (float) $amount);

        return __('cleaning_finance_guidance.validation.valid_with_balance', [
            'balance' => self::money((float) $projectedBalance),
        ]);
    }

    private static function typeDescription(string $type): string
    {
        if ($type === '') {
            return __('cleaning_finance_guidance.compact');
        }

        $key = 'cleaning_finance_guidance.types.'.$type.'.description';
        $description = __($key);

        return $description === $key ? __('cleaning_finance_guidance.compact') : $description;
    }

    private static function statusLabel(string $status): string
    {
        $key = 'cleaning_finance_guidance.statuses.'.$status;
        $label = __($key);

        return $label === $key ? $status : $label;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2).' '.config('app.currency', 'SYP');
    }
}
