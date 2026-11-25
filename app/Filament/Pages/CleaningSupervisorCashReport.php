<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Cleaning\Services\CleaningV3CashSettlementService;
use Modules\Cleaning\Services\CleaningV3SupervisorCashAccountingService;

final class CleaningSupervisorCashReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;
    protected static ?int $navigationSort = 23;
    protected string $view = 'filament.cleaning-admin.pages.supervisor-cash-report';

    public static function canAccess(): bool
    {
        return CleaningV3CashSettlementService::authorized();
    }

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return 'محاسبة مشرفي التنظيف';
    }

    public function getTitle(): string
    {
        return 'محاسبة مشرفي التنظيف';
    }

    public function accounts(): array
    {
        abort_unless(self::canAccess(), 403);
        return app(CleaningV3SupervisorCashAccountingService::class)->adminSummary();
    }
}
