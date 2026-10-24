<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\AdminNavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Cleaning\Services\CleaningSpecialOperationsReportService;

final class CleaningSpecialOperationsReport extends Page
{
    public array $report = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static ?int $navigationSort = 23;
    protected string $view = 'filament.cleaning-admin.pages.special-operations-report';

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return 'تقارير الخدمات الخاصة والمعدات';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null
            && ($user->hasAnyRole(['admin', 'Super Admin'])
                || $user->can('bookings.view')
                || $user->can('pricing.view'));
    }

    public function getTitle(): string
    {
        return 'تقرير تشغيل الخدمات الخاصة';
    }

    public function getSubheading(): ?string
    {
        return 'قيم الخدمات المسجلة والفرص المخصصة للعاملين واستخدام المعدات. القيم المسجلة ليست إيصالات نقدية.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->refreshReport();
    }

    public function refreshReport(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->report = app(CleaningSpecialOperationsReportService::class)->overview();
    }
}
