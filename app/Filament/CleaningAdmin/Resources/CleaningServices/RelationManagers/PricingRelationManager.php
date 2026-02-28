<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningServices\RelationManagers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PricingRelationManager extends RelationManager
{
    protected static string $relationship = 'pricing';

    protected static ?string $title = 'تسعير الخدمة';

    protected static ?string $recordTitleAttribute = 'property_type';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('property_type')
                    ->label('نوع العقار')
                    ->options([
                        'studio' => 'استوديو',
                        'apartment' => 'شقة',
                        'villa' => 'فيلا',
                        'office' => 'مكتب',
                    ])
                    ->required(),
                Select::make('living_room_size')
                    ->label('حجم غرفة المعيشة')
                    ->options([
                        'small' => 'صغير',
                        'medium' => 'متوسط',
                        'large' => 'كبير',
                    ]),
                TextInput::make('base_price')
                    ->label('سعر الساعة الأساسي')
                    ->numeric()
                    ->required(),
                TextInput::make('min_hours')
                    ->label('الحد الأدنى لعدد الساعات')
                    ->numeric()
                    ->step(0.5),
                TextInput::make('price_per_sqm')
                    ->label('السعر للمتر المربع')
                    ->numeric(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('property_type')
            ->columns([
                TextColumn::make('property_type')->label('نوع العقار'),
                TextColumn::make('living_room_size')->label('حجم المعيشة'),
                TextColumn::make('base_price')->label('سعر الساعة')->money('SAR'),
                TextColumn::make('min_hours')->label('الحد الأدنى ساعات'),
                TextColumn::make('price_per_sqm')->label('سعر المتر')->money('SAR'),
            ])
            ->headerActions([
                $this->createAction(),
            ])
            ->actions([
                $this->editAction(),
                $this->deleteAction(),
            ]);
    }
}
