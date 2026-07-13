<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use App\Filament\Resources\CleaningNeighborhoods\Tables\CleaningNeighborhoodsTable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class GeographicCoverage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected string $view = 'filament.cleaning-admin.pages.geographic-coverage';

    protected static ?int $navigationSort = 26;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.pages.geographic_coverage.title');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.pages.geographic_coverage.description');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can('pricing.view') || $user->can('settings.view');
    }

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.pages.geographic_coverage.title');
    }

    public function table(Table $table): Table
    {
        return CleaningNeighborhoodsTable::configure(
            $table->query(CleaningNeighborhood::query()),
            withManagementActions: false,
        )->headerActions([
            Action::make('manage')
                ->label(__('cleaning_admin.pages.geographic_coverage.manage_neighborhoods'))
                ->icon('heroicon-o-map-pin')
                ->url(CleaningNeighborhoodResource::getUrl('index')),
        ]);
    }
}
