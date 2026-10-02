<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\PlatformOperationsOverviewWidget;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

final class PlatformOperationsDashboard extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -10;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::general();
    }

    public static function getNavigationLabel(): string
    {
        return 'مركز عمليات المنصة';
    }

    public function getTitle(): string
    {
        return 'مركز عمليات المنصة';
    }

    public function getWidgets(): array
    {
        return [PlatformOperationsOverviewWidget::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
