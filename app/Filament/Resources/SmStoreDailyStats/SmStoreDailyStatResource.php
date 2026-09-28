<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDailyStats;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmStoreDailyStats\Pages\ListSmStoreDailyStats;
use App\Filament\Resources\SmStoreDailyStats\Pages\ViewSmStoreDailyStat;
use App\Filament\Resources\SmStoreDailyStats\Schemas\SmStoreDailyStatInfolist;
use App\Filament\Resources\SmStoreDailyStats\Tables\SmStoreDailyStatsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class SmStoreDailyStatResource extends Resource
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model=SmStoreDailyStat::class;
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedChartBar;
    protected static ?string $navigationLabel=null;
    protected static ?int $navigationSort=10;
    protected static bool $shouldRegisterNavigation=false;

    public static function getNavigationLabel():string{return __('supermarket_admin.daily_stats');}
    public static function getNavigationTooltip():?string{return __('supermarket_admin.tooltips.daily_stats');}
    public static function infolist(Schema $schema):Schema{return SmStoreDailyStatInfolist::configure($schema);}
    public static function table(Table $table):Table{return SmStoreDailyStatsTable::configure($table);}
    public static function canViewAny():bool{return self::dashboardAllowed('supermarket_orders.view');}
    public static function canView(Model $record):bool{return self::dashboardAllowed('supermarket_orders.view');}
    public static function canCreate():bool{return false;}
    public static function canEdit(Model $record):bool{return false;}
    public static function canDelete(Model $record):bool{return false;}
    public static function getPages():array{return ['index'=>ListSmStoreDailyStats::route('/'),'view'=>ViewSmStoreDailyStat::route('/{record}')];}
}
