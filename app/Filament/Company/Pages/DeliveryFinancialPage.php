<?php

declare(strict_types=1);

namespace App\Filament\Company\Pages;

use App\Enums\PermissionGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\Delivery\Models\DeliveryFinancialAccount;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Delivery\Services\DeliveryCompanyContextService;
use Modules\Delivery\Services\FinancialLedgerService;

final class DeliveryFinancialPage extends Page
{
    public ?DeliveryFinancialAccount $account = null;

    /** @var Collection<int, DeliveryFinancialTransaction> */
    public Collection $transactions;

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
        $this->transactions = DeliveryFinancialTransaction::query()
            ->where('account_id', $this->account->id)
            ->latest('created_at')
            ->limit(100)
            ->get();
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
}
