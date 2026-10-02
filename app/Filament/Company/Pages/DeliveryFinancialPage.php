<?php

declare(strict_types=1);

namespace App\Filament\Company\Pages;

use App\Enums\PermissionGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;
use Modules\Delivery\Enums\DeliveryFinancialDirection;
use Modules\Delivery\Enums\DeliveryFinancialTransactionType;
use Modules\Delivery\Models\DeliveryFinancialAccount;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Delivery\Services\DeliveryCompanyContextService;
use Modules\Delivery\Services\FinancialLedgerService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DeliveryFinancialPage extends Page
{
    use WithPagination;

    public ?DeliveryFinancialAccount $account = null;

    public string $transactionType = 'all';

    public int $periodDays = 30;

    public int $perPage = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.company.pages.delivery-financial';

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.financial');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.financial.nav_label');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryFinancial->value.'.view') ?? false;
    }

    public function mount(): void
    {
        $company = app(DeliveryCompanyContextService::class)->resolveFromUser(auth()->user());
        $this->account = app(FinancialLedgerService::class)->accountForCompany($company);
    }

    public function updatedTransactionType(): void
    {
        $this->resetPage();
    }

    public function updatedPeriodDays(): void
    {
        $this->periodDays = max(7, min($this->periodDays, 90));
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25;
        $this->resetPage();
    }

    /** @return array<string, string> */
    public function transactionTypeOptions(): array
    {
        $options = ['all' => __('delivery_company.financial.filters.all_types')];

        foreach (DeliveryFinancialTransactionType::cases() as $type) {
            $options[$type->value] = __('delivery_company.financial.enums.transaction_type.'.$type->value);
        }

        return $options;
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return [
            '7' => __('delivery_company.reports.filters.last_7_days'),
            '30' => __('delivery_company.reports.filters.last_30_days'),
            '90' => __('delivery_company.reports.filters.last_90_days'),
        ];
    }

    public function transactionTypeLabel(string $type): string
    {
        $key = 'delivery_company.financial.enums.transaction_type.'.$type;

        return __($key) === $key ? $type : __($key);
    }

    public function directionLabel(string $direction): string
    {
        return match ($direction) {
            DeliveryFinancialDirection::Debit->value => __('delivery_company.financial.enums.direction.debit'),
            DeliveryFinancialDirection::Credit->value => __('delivery_company.financial.enums.direction.credit'),
            default => $direction,
        };
    }

    public function getTitle(): string|Htmlable
    {
        return __('delivery_company.financial.title');
    }

    public function isNearLimit(): bool
    {
        if (! $this->account) {
            return false;
        }

        $limit = (float) $this->account->financial_limit;

        if ($limit <= 0) {
            return false;
        }

        $balance = (float) $this->account->current_balance;

        return $balance >= ($limit * 0.8) && $balance < $limit;
    }

    public function isAtOrOverLimit(): bool
    {
        if (! $this->account) {
            return false;
        }

        $limit = (float) $this->account->financial_limit;

        if ($limit <= 0) {
            return false;
        }

        return (float) $this->account->current_balance >= $limit;
    }

    public function transactions(): LengthAwarePaginator
    {
        return $this->filteredTransactionsQuery()
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function exportCsv(): StreamedResponse
    {
        $fileName = 'delivery-financial-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                __('delivery_company.financial.fields.created_at'),
                __('delivery_company.financial.fields.transaction_type'),
                __('delivery_company.financial.fields.direction'),
                __('delivery_company.financial.fields.amount'),
                __('delivery_company.financial.fields.balance_before'),
                __('delivery_company.financial.fields.balance_after'),
                __('delivery_company.financial.fields.note'),
            ]);

            foreach ($this->filteredTransactionsQuery()->latest('created_at')->latest('id')->cursor() as $transaction) {
                fputcsv($stream, [
                    $transaction->created_at?->format('Y-m-d H:i:s'),
                    $this->transactionTypeLabel((string) $transaction->transaction_type),
                    $this->directionLabel((string) $transaction->direction),
                    (string) $transaction->amount,
                    (string) $transaction->balance_before,
                    (string) $transaction->balance_after,
                    $transaction->note ?? '',
                ]);
            }

            fclose($stream);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredTransactionsQuery(): Builder
    {
        $from = now()->subDays(max(7, min($this->periodDays, 90)) - 1)->startOfDay();

        return DeliveryFinancialTransaction::query()
            ->when(
                $this->account,
                fn (Builder $query): Builder => $query->where('account_id', $this->account?->id),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->where('created_at', '>=', $from)
            ->when(
                $this->transactionType !== 'all',
                fn (Builder $query): Builder => $query->where('transaction_type', $this->transactionType),
            );
    }
}
