<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DeliveryOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('delivery_company.orders.sections.customer'))
                    ->schema([
                        TextInput::make('customer_name')
                            ->label(__('delivery_company.orders.fields.customer_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('customer_phone')
                            ->label(__('delivery_company.orders.fields.customer_phone'))
                            ->tel()
                            ->maxLength(50),
                        Textarea::make('customer_notes')
                            ->label(__('delivery_company.orders.fields.customer_notes'))
                            ->rows(2)
                            ->maxLength(2000),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.orders.sections.pickup'))
                    ->schema([
                        TextInput::make('pickup_address')
                            ->label(__('delivery_company.orders.fields.pickup_address'))
                            ->required()
                            ->maxLength(500)
                            ->columnSpanFull(),
                        TextInput::make('pickup_latitude')
                            ->label(__('delivery_company.orders.fields.pickup_latitude'))
                            ->required()
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('pickup_longitude')
                            ->label(__('delivery_company.orders.fields.pickup_longitude'))
                            ->required()
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.orders.sections.dropoff'))
                    ->schema([
                        TextInput::make('dropoff_address')
                            ->label(__('delivery_company.orders.fields.dropoff_address'))
                            ->required()
                            ->maxLength(500)
                            ->columnSpanFull(),
                        TextInput::make('dropoff_latitude')
                            ->label(__('delivery_company.orders.fields.dropoff_latitude'))
                            ->required()
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('dropoff_longitude')
                            ->label(__('delivery_company.orders.fields.dropoff_longitude'))
                            ->required()
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                    ])
                    ->columns(2),
            ]);
    }
}
