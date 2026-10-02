<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
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
                        TextInput::make('pickup_location_input')
                            ->label(__('delivery_company.orders.fields.map_coordinates'))
                            ->helperText(__('delivery_company.orders.fields.map_coordinates_help'))
                            ->placeholder('36.202104, 37.134260')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set): mixed => self::fillCoordinates($state, $set, 'pickup_latitude', 'pickup_longitude'))
                            ->dehydrated(false)
                            ->columnSpanFull(),
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
                        TextInput::make('dropoff_location_input')
                            ->label(__('delivery_company.orders.fields.map_coordinates'))
                            ->helperText(__('delivery_company.orders.fields.map_coordinates_help'))
                            ->placeholder('36.202104, 37.134260')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set): mixed => self::fillCoordinates($state, $set, 'dropoff_latitude', 'dropoff_longitude'))
                            ->dehydrated(false)
                            ->columnSpanFull(),
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

    private static function fillCoordinates(
        ?string $state,
        Set $set,
        string $latitudeField,
        string $longitudeField,
    ): bool {
        if (blank($state)) {
            return false;
        }

        $value = urldecode(mb_trim((string) $state));
        $matched = preg_match(
            '/(-?\d{1,2}(?:\.\d+)?)\s*[,،]\s*(-?\d{1,3}(?:\.\d+)?)/u',
            $value,
            $matches,
        );

        if ($matched !== 1 && preg_match('/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/', $value, $matches) !== 1) {
            return false;
        }

        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return false;
        }

        $set($latitudeField, $latitude);
        $set($longitudeField, $longitude);

        return true;
    }
}
