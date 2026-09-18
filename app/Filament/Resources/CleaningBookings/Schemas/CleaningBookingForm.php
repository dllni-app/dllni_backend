<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use App\Enums\GenderPreference;
use App\Models\User;
use App\Models\Worker;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;

final class CleaningBookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات الحجز الأساسية')
                ->description('تعديل بيانات الحجز التشغيلية فقط. التسعير والمساحة والقيم المحسوبة لا يتم تعديلها من هذه الصفحة.')
                ->schema([
                    TextInput::make('booking_number')
                        ->label('رقم الحجز')
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('customer_id')
                        ->label('العميل')
                        ->relationship('customer', 'name')
                        ->getOptionLabelFromRecordUsing(fn (User $record): string => sprintf('%s (%s)', $record->name, $record->phone ?: '-'))
                        ->searchable(['name', 'phone'])
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->label('الحالة')
                        ->options(collect(CleaningBookingStatus::cases())
                            ->mapWithKeys(fn (CleaningBookingStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->required(),
                    Select::make('property_type')
                        ->label('نوع العقار / الخدمة')
                        ->options(self::propertyTypeOptions())
                        ->required(),
                    TextInput::make('number_of_workers')
                        ->label('عدد العاملين المطلوب')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(20)
                        ->required(),
                    Select::make('gender_preference')
                        ->label('الجنس المطلوب للعاملين')
                        ->options([
                            GenderPreference::Male->value => 'ذكور',
                            GenderPreference::Female->value => 'إناث',
                            GenderPreference::Any->value => 'لا يوجد تفضيل',
                        ]),
                    DatePicker::make('scheduled_date')
                        ->label('تاريخ الخدمة')
                        ->required(),
                    TimePicker::make('scheduled_time')
                        ->label('وقت الخدمة (بتوقيت حلب)')
                        ->seconds(false)
                        ->timezone((string) config('app.timezone', 'UTC'))
                        ->displayFormat('H:i')
                        ->native(false)
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('الموقع والعنوان')
                ->schema([
                    Select::make('neighborhood_id')
                        ->label('الحي')
                        ->relationship(
                            name: 'neighborhood',
                            titleAttribute: 'name_ar',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->orderBy('sort_order')
                                ->orderBy('name_ar'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (CleaningNeighborhood $record): string => $record->name_ar ?: $record->name_en ?: '#'.$record->id)
                        ->searchable(['name_ar', 'name_en'])
                        ->preload(),
                    TextInput::make('neighborhood_name')
                        ->label('اسم المنطقة المحفوظ')
                        ->maxLength(255),
                    TextInput::make('address_latitude')
                        ->label('خط العرض')
                        ->numeric()
                        ->step('0.00000001'),
                    TextInput::make('address_longitude')
                        ->label('خط الطول')
                        ->numeric()
                        ->step('0.00000001'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('اختيار العامل والفريق')
                ->description('العامل المحدد مسبقاً هو العامل الذي يختاره العميل قبل بدء توزيع الطلب. العامل المعيّن فعلياً تتم إدارته من إجراءات الفريق في قائمة الحجوزات.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Select::make('assignment_mode')
                        ->label('طريقة اختيار العامل')
                        ->options([
                            CleaningAssignmentMode::OpenCount->value => 'طلب مفتوح للعمال',
                            CleaningAssignmentMode::PreferredWorker->value => 'عامل محدد مسبقاً',
                        ])
                        ->live(),
                    Select::make('preferred_worker_id')
                        ->label('العامل المحدد مسبقاً')
                        ->helperText('استخدم هذا الحقل فقط عندما يكون العميل قد اختار عاملاً بعينه قبل إرسال الطلب.')
                        ->relationship(
                            name: 'preferredWorker',
                            titleAttribute: 'first_name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Worker $record): string => $record->user?->name ?: $record->first_name ?: '#'.$record->id)
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => $get('assignment_mode') === CleaningAssignmentMode::PreferredWorker->value),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('الغرف المشمولة بالخدمة')
                ->description('يمكن تعديل الغرف التي يشملها هذا الحجز فقط. لن يتم تغيير السعر أو المساحة أو التقديرات المحسوبة تلقائياً.')
                ->schema([
                    CheckboxList::make('selected_room_keys')
                        ->label('الغرف المختارة')
                        ->options(fn (?CleaningBooking $record): array => self::roomOptions($record))
                        ->columns(3)
                        ->bulkToggleable()
                        ->required(fn (?CleaningBooking $record): bool => self::hasRooms($record))
                        ->visible(fn (?CleaningBooking $record): bool => self::hasRooms($record))
                        ->columnSpanFull(),
                ])
                ->visible(fn (?CleaningBooking $record): bool => self::hasRooms($record))
                ->columnSpanFull(),
        ]);
    }

    private static function propertyTypeOptions(): array
    {
        return [
            'apartment' => 'شقة',
            'villa' => 'فيلا',
            'house' => 'منزل',
            'office' => 'مكتب',
            'studio' => 'استوديو',
            'event_assistance' => 'مساعدة مناسبة',
        ];
    }

    private static function hasRooms(?CleaningBooking $booking): bool
    {
        return self::roomOptions($booking) !== [];
    }

    /** @return array<string, string> */
    private static function roomOptions(?CleaningBooking $booking): array
    {
        if (! $booking instanceof CleaningBooking) {
            return [];
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $rooms = WorkerRoomAssignmentPlanner::generateRoomBlueprints($details);

        if ($rooms === []) {
            $booking->loadMissing('rooms');
            $rooms = $booking->rooms
                ->map(fn ($room): array => [
                    'room_key' => (string) $room->room_key,
                    'room_type' => (string) $room->room_type,
                    'room_size' => (string) $room->room_size,
                    'display_label' => (string) $room->display_label,
                ])
                ->all();
        }

        $options = [];
        foreach ($rooms as $room) {
            $key = (string) ($room['room_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $options[$key] = self::roomLabel(
                $key,
                (string) ($room['room_type'] ?? ''),
                (string) ($room['room_size'] ?? ''),
            );
        }

        return $options;
    }

    private static function roomLabel(string $roomKey, string $roomType, string $roomSize): string
    {
        $type = match ($roomType) {
            'bedroom' => 'غرفة نوم',
            'bathroom' => 'حمّام',
            'toilet' => 'دورة مياه',
            'kitchen' => 'مطبخ',
            'living_room' => 'غرفة معيشة',
            'balcony' => 'شرفة',
            'corridor' => 'ممر',
            'shed' => 'مستودع',
            default => 'غرفة',
        };

        $size = match ($roomSize) {
            'small' => 'صغيرة',
            'medium' => 'متوسطة',
            'large' => 'كبيرة',
            default => null,
        };

        return $size !== null ? $type.' '.$size : $type;
    }
}
