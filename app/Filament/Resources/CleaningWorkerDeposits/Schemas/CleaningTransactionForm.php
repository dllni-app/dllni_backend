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
                            ->options([
                                'deposit' => __('cleaning_admin.transactions.types.deposit'),
                                'debt' => __('cleaning_finance.types.debt'),
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
                    ->description(app()->isLocale('ar')
                        ? 'الدين الإداري يضاف إلى رصيد الإيداع مع بقائه معلّماً كدين، أما المديونية فهي رصيد مستقل ينتج عند تجاوز الاستحقاقات المالية لرصيد الإيداع.'
                        : 'An administration loan is added to the deposit balance while remaining marked as a loan. Indebtedness is separate and is created when financial dues exceed the deposit.')
                    ->visible(fn (Get $get): bool => self::worker($get('worker_id')) instanceof Worker)
                    ->columns(['default' => 2, 'md' => 3, 'xl' => 6])
                    ->schema([
                        self::moneyMetric('deposit_balance', 'depositBalance', app()->isLocale('ar') ? 'رصيد الإيداع' : 'Deposit balance'),
                        self::moneyMetric('admin_loan_balance', 'adminLoanBalance', app()->isLocale('ar') ? 'الدين الإداري ضمن الإيداع' : 'Administration loan in deposit'),
                        self::moneyMetric('debt_balance', 'debtBalance', app()->isLocale('ar') ? 'المديونية الحالية' : 'Current indebtedness'),
                        self::moneyMetric('allowed_debt_limit', 'allowedDebtLimit', app()->isLocale('ar') ? 'حد المديونية' : 'Indebtedness limit'),
                        self::moneyMetric('total_revenue', 'totalRevenue', app()->isLocale('ar') ? 'إجمالي الإيرادات' : 'Total revenue'),
                        self::moneyMetric('administration_due_balance', 'administrationRevenueBalance', app()->isLocale('ar') ? 'استحقاقات الإدارة' : 'Administration dues'),
                    ]),
                Section::make(__('cleaning_finance_guidance.form.transaction_section'))
                    ->description(__('cleaning_finance_guidance.form.transaction_section_description'))
                    ->columns(2)
                    ->schema([
                        Select::make('suggested_amount')
                            ->label(__('cleaning_finance_guidance.fields.suggested_amount'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.suggested_amount'))
                            ->options(fn (Get $get): array => self::suggestedAmounts($get('worker_id'), $get('type')))
                            ->visible(fn (Get $get): bool => $get('type') !== 'refund' && self::suggestedAmounts($get('worker_id'), $get('type')) !== [])
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
                            ->required(fn (Get $get): bool => $get('type') !== 'refund')
                            ->visible(fn (Get $get): bool => $get('type') !== 'refund')
                            ->live(debounce: 400)
                            ->helperText(fn (Get $get): string => self::amountHelper($get('worker_id'), $get('type')))
                            ->rules(fn (Get $get): array => [self::amountRule($get('worker_id'), $get('type'))]),
                        Placeholder::make('amount_validation')
                            ->label(__('cleaning_finance_guidance.fields.validation_result'))
                            ->content(fn (Get $get): string => self::validationResult($get('worker_id'), $get('type'), $get('amount')))
                            ->visible(fn (Get $get): bool => $get('type') !== 'refund')
                            ->columnSpanFull(),
                        Placeholder::make('automatic_refund_summary')
                            ->label(app()->isLocale('ar') ? 'نتيجة الاسترداد التلقائي' : 'Automatic refund result')
                            ->content(fn (Get $get): string => self::automaticRefundSummary($get('worker_id')))
                            ->visible(fn (Get $get): bool => $get('type') === 'refund')
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('cleaning_admin.transactions.fields.notes'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.notes'))
                            ->required(fn (Get $get): bool => $get('type') === 'debt')
                            ->helperText(fn (Get $get): ?string => $get('type') === 'debt'
                                ? (app()->isLocale('ar') ? 'سبب الدين الإداري مطلوب، وسيظهر المبلغ داخل رصيد الإيداع مع تنبيه بأنه دين.' : 'A reason is required. The amount will appear in the deposit balance with an administration-loan warning.')
                                : null)
                            ->rows(4)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function moneyMetric(string $name, string $snapshotKey, string $label): Placeholder
    {
        return Placeholder::make($name.'_metric')
            ->label($label)
            ->content(fn (Get $get): string => self::money((float) (self::snapshot($get('worker_id'))[$snapshotKey] ?? 0)));
    }

    private static function workerOptions(): array
    {
        return Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('module_type', UserModuleType::CleaningWorker))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Worker $worker): array => [$worker->id => ($worker->first_name ?: ('#'.$worker->id)).' (#'.$worker->id.')'])
            ->all();
    }

    private static function worker(mixed $workerId): ?Worker
    {
        if (! is_numeric($workerId) || (int) $workerId <= 0) {
            return null;
        }

        $workerId = (int) $workerId;
        $cacheKey = 'cleaning-transaction-form.worker.'.$workerId;
        if (request()->attributes->has($cacheKey)) {
            $cached = request()->attributes->get($cacheKey);

            return $cached instanceof Worker ? $cached : null;
        }

        try {
            $worker = app(AdminCleaningTransactionService::class)->findWorker($workerId);
            request()->attributes->set($cacheKey, $worker);

            return $worker;
        } catch (Throwable) {
            request()->attributes->set($cacheKey, null);

            return null;
        }
    }

    private static function snapshot(mixed $workerId): array
    {
        if (! is_numeric($workerId) || (int) $workerId <= 0) {
            return [];
        }

        $workerId = (int) $workerId;
        $cacheKey = 'cleaning-transaction-form.snapshot.'.$workerId;
        if (request()->attributes->has($cacheKey)) {
            $cached = request()->attributes->get($cacheKey);

            return is_array($cached) ? $cached : [];
        }

        $worker = self::worker($workerId);
        $snapshot = $worker instanceof Worker ? app(AdminCleaningTransactionService::class)->snapshot($worker) : [];
        request()->attributes->set($cacheKey, $snapshot);

        return $snapshot;
    }

    private static function suggestedAmounts(mixed $workerId, mixed $type): array
    {
        $worker = self::worker($workerId);
        if (! $worker instanceof Worker || ! is_string($type) || $type === '') {
            return [];
        }

        return app(AdminCleaningTransactionService::class)->suggestedAmounts($worker, $type);
    }

    private static function amountRule(mixed $workerId, mixed $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($workerId, $type): void {
            $worker = self::worker($workerId);
            if (! $worker instanceof Worker) {
                $fail(__('cleaning_finance_guidance.validation.worker_required'));

                return;
            }
            if (! is_numeric($value)) {
                $fail(__('cleaning_finance_guidance.validation.amount_positive'));

                return;
            }

            $message = app(AdminCleaningTransactionService::class)->validationMessage($worker, (string) $type, (float) $value);
            if ($message !== null) {
                $fail($message);
            }
        };
    }

    private static function amountHelper(mixed $workerId, mixed $type): string
    {
        $snapshot = self::snapshot($workerId);
        if ($snapshot === [] || ! is_string($type) || $type === '') {
            return __('cleaning_finance_guidance.fields.amount_helper_default');
        }

        return match ($type) {
            'deposit' => app()->isLocale('ar')
                ? 'يسدد الإيداع المديونية أولاً. المديونية الحالية: '.self::money((float) $snapshot['debtBalance']).'.'
                : 'The deposit settles indebtedness first. Current indebtedness: '.self::money((float) $snapshot['debtBalance']).'.',
            'debt' => app()->isLocale('ar')
                ? 'يضاف الدين الإداري إلى رصيد الإيداع، ولا يمكن إضافته إذا كان للعامل رصيد إيداع أو مديونية قائمة.'
                : 'The administration loan is added to the deposit balance and cannot be added while a deposit or indebtedness already exists.',
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

        return __('cleaning_finance_guidance.validation.valid_with_balance', [
            'balance' => self::money((float) $service->projectedBalance($worker, $type, (float) $amount)),
        ]);
    }

    private static function automaticRefundSummary(mixed $workerId): string
    {
        $snapshot = self::snapshot($workerId);
        if ($snapshot === []) {
            return __('cleaning_finance_guidance.validation.select_worker_first');
        }

        $deposit = self::money((float) ($snapshot['grossRefundBalance'] ?? $snapshot['depositBalance'] ?? 0));
        $loan = self::money((float) ($snapshot['adminLoanBalance'] ?? 0));
        $administrationDue = self::money((float) ($snapshot['administrationRevenueBalance'] ?? 0));
        $workerRefund = self::money((float) ($snapshot['maxRefundable'] ?? 0));

        return app()->isLocale('ar')
            ? "سيتم إغلاق رصيد الإيداع ({$deposit}) بهذا الترتيب: استرداد الدين الإداري أولاً ({$loan})، ثم تحويل استحقاقات الإدارة ({$administrationDue}) إلى إيرادات الإدارة المسحوبة، ثم إعادة المبلغ المتبقي للعامل ({$workerRefund})."
            : "The deposit balance ({$deposit}) will be closed in this order: recover the administration loan first ({$loan}), move administration dues ({$administrationDue}) to withdrawn administration revenue, then refund the remaining amount to the worker ({$workerRefund}).";
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2).' '.config('app.currency', 'SYP');
    }
}
