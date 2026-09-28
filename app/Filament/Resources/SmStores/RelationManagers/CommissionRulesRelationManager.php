<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\RelationManagers;

use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Models\SmCommissionRule;

final class CommissionRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'commissionRules';

    protected static ?string $title = 'قواعد عمولة المنصة';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::allowed('platform_finance.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('commission_type')->label('النوع')->badge()
                    ->formatStateUsing(fn ($state): string => ($state?->value ?? $state) === SmCommissionType::Percentage->value ? 'نسبة مئوية' : 'مبلغ ثابت'),
                TextColumn::make('value')->label('القيمة')->formatStateUsing(
                    fn ($state, SmCommissionRule $record): string => $record->commission_type === SmCommissionType::Percentage
                        ? number_format((float) $state, 2).'%'
                        : number_format((float) $state, 2).' ل.س'
                ),
                TextColumn::make('min_order_amount')->label('حد الطلب الأدنى')->money(config('app.currency', 'SYP'))->placeholder('—'),
                TextColumn::make('max_commission_amount')->label('سقف العمولة')->money(config('app.currency', 'SYP'))->placeholder('—'),
                TextColumn::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('فوراً'),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('مفتوح'),
                IconColumn::make('is_default')->label('افتراضية')->boolean(),
                IconColumn::make('is_active')->label('فعالة')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('إضافة قاعدة عمولة')
                    ->visible(fn (): bool => self::allowed('platform_finance.create'))
                    ->form($this->ruleForm())
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['store_id'] = $this->getOwnerRecord()->getKey();

                        return $data;
                    })
                    ->after(fn (SmCommissionRule $record) => $this->afterSave($record, 'created')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => self::allowed('platform_finance.update'))
                    ->form($this->ruleForm())
                    ->after(fn (SmCommissionRule $record) => $this->afterSave($record, 'updated')),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }

    /** @return array<int, mixed> */
    private function ruleForm(): array
    {
        return [
            Select::make('commission_type')->label('نوع العمولة')->required()->native(false)->options([
                SmCommissionType::Percentage->value => 'نسبة مئوية',
                SmCommissionType::Fixed->value => 'مبلغ ثابت',
            ]),
            TextInput::make('value')->label('القيمة')->numeric()->required()->minValue(0),
            TextInput::make('min_order_amount')->label('حد الطلب الأدنى')->numeric()->minValue(0)->nullable(),
            TextInput::make('max_commission_amount')->label('سقف العمولة')->numeric()->minValue(0)->nullable(),
            DateTimePicker::make('starts_at')->label('يبدأ في')->nullable(),
            DateTimePicker::make('ends_at')->label('ينتهي في')->nullable()->rules(['nullable', 'date', 'after_or_equal:starts_at']),
            Toggle::make('is_default')->label('قاعدة افتراضية')->default(false),
            Toggle::make('is_active')->label('فعالة')->default(true),
        ];
    }

    private function afterSave(SmCommissionRule $record, string $event): void
    {
        if ($record->is_default) {
            SmCommissionRule::query()
                ->where('store_id', $record->store_id)
                ->whereKeyNot($record->getKey())
                ->update(['is_default' => false]);
        }

        activity('supermarket_commission_rules')
            ->causedBy(auth()->user())
            ->performedOn($record)
            ->withProperties(['event' => $event, 'store_id' => $record->store_id])
            ->log('supermarket_commission_rule_'.$event);
    }
}
