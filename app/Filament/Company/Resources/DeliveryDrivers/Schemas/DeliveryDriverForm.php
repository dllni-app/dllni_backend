<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Delivery\Models\DeliveryDriver;

final class DeliveryDriverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('delivery_company.drivers.sections.profile'))
                    ->schema([
                        Select::make('user_id')
                            ->label(__('delivery_company.drivers.fields.user'))
                            ->searchable()
                            ->required()
                            ->options(fn (): array => self::eligibleUserOptions())
                            ->disabledOn('edit'),
                        TextInput::make('first_name')
                            ->label(__('delivery_company.drivers.fields.first_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('delivery_company.drivers.fields.phone'))
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('vehicle_type')
                            ->label(__('delivery_company.drivers.fields.vehicle_type'))
                            ->maxLength(100),
                        TextInput::make('plate_number')
                            ->label(__('delivery_company.drivers.fields.plate_number'))
                            ->maxLength(50),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function eligibleUserOptions(): array
    {
        $assignedUserIds = DeliveryDriver::query()->pluck('user_id');

        return User::query()
            ->when($assignedUserIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereNotIn('id', $assignedUserIds))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => mb_trim($user->name.' ('.$user->email.')'),
            ])
            ->all();
    }
}
