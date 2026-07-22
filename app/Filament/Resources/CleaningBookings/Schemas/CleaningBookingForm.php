<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingStatus;

final class CleaningBookingForm
{
    public static function configure(Schema $schema): Schema
    {
        $statusOptions = collect(CleaningBookingStatus::cases())
            ->mapWithKeys(fn (CleaningBookingStatus $status): array => [$status->value => $status->label()])
            ->all();

        return $schema
            ->components([
                Section::make('بيانات الحجز')
                    ->schema([
                        TextInput::make('booking_number')
                            ->label('رقم الحجز')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('status')
                            ->label('الحالة')
                            ->options($statusOptions)
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('worker_id')
                            ->label('العامل')
                            ->relationship(
                                name: 'worker',
                                titleAttribute: 'first_name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                            )
                            ->searchable()
                            ->preload(),
                        TextInput::make('number_of_workers')
                            ->label('عدد العاملين')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20)
                            ->default(1)
                            ->required(),
                        DatePicker::make('scheduled_date')
                            ->label('التاريخ')
                            ->required(),
                        TimePicker::make('scheduled_time')
                            ->label('الوقت')
                            ->seconds(false)
                            ->displayFormat('h:i A')
                            ->native(false)
                            ->required(),
                        TextInput::make('cancellation_fee')
                            ->label('رسوم الإلغاء')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->suffix('ل.س')
                            ->helperText('يمكن تعديل رسوم الإلغاء يدوياً للعميل أو العامل.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('التقديرات والتسعير')
                    ->description('القيم التالية للعرض فقط، وتظهر بأرقام صحيحة من دون كسور عشرية.')
                    ->schema([
                        self::readOnlyInteger('estimated_sqm', 'المساحة التقديرية'),
                        self::readOnlyInteger('estimated_hours', 'الساعات التقديرية'),
                        self::readOnlyInteger('total_hours', 'إجمالي الساعات'),
                        self::readOnlyPrice('base_price', 'السعر الأساسي'),
                        self::readOnlyPrice('addons_total', 'الإضافات'),
                        self::readOnlyPrice('travel_fee', 'رسوم التنقل'),
                        self::readOnlyInteger('travel_distance_km', 'مسافة التنقل (كم)'),
                        self::readOnlyPrice('admin_margin_amount', 'هامش الإدارة (يُخصم من حصة العامل)'),
                        self::readOnlyPrice('total_price', 'الإجمالي (يدفعه العميل)'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function readOnlyInteger(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->formatStateUsing(fn (mixed $state): string => self::integer($state))
            ->disabled()
            ->dehydrated(false);
    }

    private static function readOnlyPrice(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->formatStateUsing(fn (mixed $state): string => self::integer($state))
            ->suffix('ل.س')
            ->disabled()
            ->dehydrated(false);
    }

    private static function integer(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return number_format((int) round((float) $value), 0, '.', ',');
    }
}
