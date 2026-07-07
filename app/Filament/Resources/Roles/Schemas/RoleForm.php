<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

final class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم الدور')
                    ->helperText('استخدم اسماً واضحاً للدور مثل: مدير عمليات التنظيف أو دعم العملاء.')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('guard_name')
                    ->label('نطاق الصلاحية')
                    ->helperText('اترك القيمة web للأدوار المستخدمة داخل لوحة الإدارة.')
                    ->default('web')
                    ->required()
                    ->maxLength(255)
                    ->dehydrated(),
                CheckboxList::make('permissions')
                    ->label('الصلاحيات')
                    ->helperText('اختر الصلاحيات حسب مهمة الدور. تظهر الصلاحيات هنا بأسماء عربية بدلاً من الأكواد التقنية.')
                    ->options(self::permissionOptions())
                    ->columns(2)
                    ->searchable()
                    ->dehydrated(true),
            ]);
    }

    /** @return array<string, string> */
    private static function permissionOptions(): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name', 'name')
            ->map(fn (string $permission): string => ArabicDashboardLabels::permissionName($permission))
            ->all();
    }
}
