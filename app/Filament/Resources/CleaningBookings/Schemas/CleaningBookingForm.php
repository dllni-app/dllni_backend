<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Schemas;

use App\Enums\GenderPreference;
use App\Models\User;
use App\Models\Worker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class CleaningBookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات الحجز الأساسية')
                ->description('يمكن تعديل معلومات الحجز الأساسية من لوحة التحكم مباشرة.')
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
                ->description('إعدادات متقدمة لاختيار العامل المفضل أو العامل الأساسي. إدارة الفريق اليومية تبقى من زر إدارة في قائمة الحجوزات.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Select::make('assignment_mode')
                        ->label('طريقة اختيار العامل')
                        ->options([
                            CleaningAssignmentMode::OpenCount->value => 'طلب مفتوح للعمال',
                            CleaningAssignmentMode::PreferredWorker->value => 'عامل مفضل',
                        ]),
                    Select::make('preferred_worker_id')
                        ->label('العامل المفضل')
                        ->relationship(
                            name: 'preferredWorker',
                            titleAttribute: 'first_name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Worker $record): string => $record->user?->name ?: $record->first_name ?: '#'.$record->id)
                        ->searchable()
                        ->preload(),
                    Select::make('worker_id')
                        ->label('العامل الأساسي المحفوظ')
                        ->relationship(
                            name: 'worker',
                            titleAttribute: 'first_name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Worker $record): string => $record->user?->name ?: $record->first_name ?: '#'.$record->id)
                        ->searchable()
                        ->preload(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('التقديرات والتسعير')
                ->description('يمكن للإدارة تعديل القيم المالية والتقديرية المحفوظة للحجز.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    self::numeric('estimated_sqm', 'المساحة التقديرية'),
                    self::numeric('estimated_hours', 'الساعات التقديرية'),
                    self::numeric('total_hours', 'إجمالي الساعات'),
                    self::money('base_price', 'السعر الأساسي'),
                    self::money('addons_total', 'الإضافات'),
                    self::money('extension_fee_total', 'رسوم تمديد الوقت'),
                    self::money('travel_fee', 'رسوم التنقل'),
                    self::numeric('travel_distance_km', 'مسافة التنقل (كم)', 0.001),
                    self::money('admin_margin_amount', 'هامش الإدارة'),
                    self::money('cancellation_fee', 'رسوم الإلغاء'),
                    self::money('total_price', 'الإجمالي المطلوب من العميل'),
                    Toggle::make('is_pricing_final')->label('التسعير نهائي'),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('تفاصيل الخدمة')
                ->description('هذه الحقول تحفظ التفاصيل الكاملة كما أرسلها التطبيق. استخدم JSON صالح عند التعديل.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Textarea::make('property_details_json')
                        ->label('تفاصيل العقار أو المناسبة')
                        ->rows(10)
                        ->rules(['nullable', 'json'])
                        ->dehydrated()
                        ->columnSpanFull(),
                    Textarea::make('cleaning_services_json')
                        ->label('تفاصيل خدمات التنظيف')
                        ->rows(8)
                        ->rules(['nullable', 'json'])
                        ->dehydrated()
                        ->columnSpanFull(),
                    Toggle::make('terms_accepted')
                        ->label('وافق العميل على الشروط'),
                ])
                ->columnSpanFull(),
            Section::make('الإلغاء وملاحظات التنفيذ')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Select::make('cancelled_by_role')
                        ->label('جهة الإلغاء')
                        ->options([
                            'customer' => 'العميل',
                            'worker' => 'العامل',
                            'admin' => 'الإدارة',
                        ]),
                    Textarea::make('cancellation_reason')
                        ->label('سبب الإلغاء')
                        ->rows(3),
                    Textarea::make('worker_completion_message')
                        ->label('ملاحظة العامل عند إنهاء العمل')
                        ->rows(3),
                    Textarea::make('customer_completion_rejection_message')
                        ->label('سبب رفض العميل إنهاء الطلب')
                        ->rows(3),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('أوقات التنفيذ')
                ->description('جميع الأوقات المعروضة والمعدلة بتوقيت سوريا - حلب.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    self::dateTime('started_travel_at', 'بدأ العامل بالتوجه'),
                    self::dateTime('arrived_at', 'وصل العامل'),
                    self::dateTime('work_started_at', 'بدأ العمل'),
                    self::dateTime('work_finished_at', 'انتهى العمل'),
                    self::dateTime('customer_confirmed_at', 'تأكيد العميل'),
                    self::dateTime('completion_rejected_at', 'وقت رفض الإنهاء'),
                    self::dateTime('cancelled_at', 'وقت الإلغاء'),
                ])
                ->columns(2)
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

    private static function numeric(string $name, string $label, float $step = 0.01): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->step($step)
            ->minValue(0);
    }

    private static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->step(1)
            ->suffix('ل.س');
    }

    private static function dateTime(string $name, string $label): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->seconds(false)
            ->timezone((string) config('app.dashboard_timezone', 'Asia/Damascus'))
            ->displayFormat('Y-m-d H:i')
            ->native(false);
    }
}
