<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanyStaff\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Delivery\Models\DeliveryCompanyStaff;

final class DeliveryCompanyStaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('delivery_company.staff.sections.account'))
                ->schema([
                    Select::make('user_id')
                        ->label(__('delivery_company.staff.fields.user'))
                        ->searchable()
                        ->required()
                        ->options(fn (): array => self::eligibleUserOptions())
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label(__('delivery_company.staff.fields.name'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('phone')
                                ->label(__('delivery_company.staff.fields.phone'))
                                ->tel()
                                ->required()
                                ->unique(User::class, 'phone')
                                ->maxLength(50),
                            TextInput::make('email')
                                ->label(__('delivery_company.staff.fields.email'))
                                ->email()
                                ->unique(User::class, 'email')
                                ->maxLength(255),
                            TextInput::make('temporary_password')
                                ->label(__('delivery_company.drivers.fields.initial_password'))
                                ->password()
                                ->required()
                                ->minLength(8)
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            $user = User::query()->create([
                                'name' => (string) $data['name'],
                                'phone' => (string) $data['phone'],
                                'email' => filled($data['email'] ?? null) ? (string) $data['email'] : null,
                                'password' => (string) $data['temporary_password'],
                                'is_active' => true,
                            ]);

                            return (int) $user->getKey();
                        })
                        ->disabledOn('edit'),
                    Select::make('role_key')
                        ->label(__('delivery_company.staff.fields.role'))
                        ->required()
                        ->default('operations')
                        ->options([
                            'operations' => __('delivery_company.staff.roles.operations'),
                        ]),
                    Toggle::make('is_active')
                        ->label(__('delivery_company.staff.fields.is_active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    /** @return array<int, string> */
    private static function eligibleUserOptions(): array
    {
        $assignedUserIds = DeliveryCompanyStaff::query()->pluck('user_id');

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'delivery_company_staff'))
            ->when(
                $assignedUserIds->isNotEmpty(),
                fn (Builder $query): Builder => $query->whereNotIn('id', $assignedUserIds),
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => mb_trim($user->name.' ('.($user->email ?: $user->phone).')'),
            ])
            ->all();
    }
}
