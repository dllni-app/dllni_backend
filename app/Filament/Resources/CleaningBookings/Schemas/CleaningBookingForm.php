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
                ->description('يمكن للإدارة تعديل معلومات الحجز الأساسية مباشرة من لوحة التحكم.')
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
                    TextInput::make('booking_kind')
                        ->label('نوع الحجز الداخلي')
                        ->maxLength(100),
                    TextInput::make('property_type')
                        ->label('نوع العقار / الخدمة')
                        ->maxLength(100)
                        ->required(),
                    Select::make('cleaning_event_type_id')
                        ->label('نوع المناسبة')
                        ->relationship('eventType', 'name')
                        ->searchable()
                        ->preload(),
                    TextInput::make('number_of_workers')
                        ->label('عدد العاملين المطلوب')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
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
                        ->label('وقت الخدمة')
                        ->seconds(false)
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
                ->description('إعدادات متقدمة للتعيين. جلسات الحجوزات متعددة الأيام تبقى قابلة للإدارة من قسم الجلسات.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Select::make('assignment_mode')
                        ->label('طريقة اختيار العامل')
                        ->options([
                            CleaningAssignmentMode::OpenCount->value => 'طلب مفتوح للعمال',
                            CleaningAssignmentMode::PreferredWorker->value => 'عامل مفضل',
                        ]),
                    Select::make('worker_scope')
                        ->label('نطاق العمال')
                        ->options([
                            'any' => 'أي عامل مؤهل',
                            'specific' => 'عمال محددون فقط',
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
                    Select::make('specific_worker_ids')
                        ->label('العمال المحددون')
                        ->multiple()
                        ->options(fn (): array => Worker::query()
                            ->where('is_active', true)
                            ->orderBy('first_name')
                            ->pluck('first_name', 'id')
                            ->all())
                        ->searchable()
                        ->preload(),
                    Select::make('work_environment_beneficiary_presence')
                        ->label('وجود المستفيد أثناء العمل')
                        ->options([
                            'present' => 'موجود',
                            'absent' => 'غير موجود',
                            'unknown' => 'غير محدد',
                        ]),
                    Toggle::make('female_worker_safety_pledge_accepted')
                        ->label('تم قبول تعهد سلامة العاملات'),
                    TextInput::make('female_worker_safety_pledge_version')
                        ->label('نسخة تعهد السلامة')
                        ->maxLength(100),
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
                    Toggle::make('terms_accepted')->label('وافق العميل على الشروط'),
                    TextInput::make('cancellation_policy_id')->label('معرف سياسة الإلغاء')->numeric()->minValue(1),
                    TextInput::make('billing_policy_id')->label('معرف سياسة الفوترة')->numeric()->minValue(1),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('إعدادات الوقت المفتوح')
                ->collapsible()
                ->collapsed()
                ->schema([
                    self::money('open_time_hourly_rate', 'السعر بالساعة'),
                    self::integer('open_time_minimum_minutes', 'الحد الأدنى بالدقائق'),
                    self::integer('open_time_rounding_minutes', 'دقائق التقريب'),
                    self::integer('open_time_expected_max_minutes', 'المدة القصوى المتوقعة'),
                    self::integer('open_time_hard_max_minutes', 'الحد الأقصى الصلب'),
                    self::integer('open_time_warning_minutes', 'دقائق التحذير'),
                    self::integer('open_time_actual_minutes', 'الدقائق الفعلية'),
                    self::integer('open_time_billable_minutes', 'الدقائق المحتسبة'),
                    self::money('open_time_final_amount', 'المبلغ النهائي للوقت المفتوح'),
                    TextInput::make('open_time_end_status')->label('حالة طلب الإنهاء')->maxLength(100),
                    self::dateTime('open_time_ceiling_ends_at', 'نهاية الحد الزمني'),
                    self::dateTime('open_time_end_requested_at', 'وقت طلب الإنهاء'),
                    self::dateTime('open_time_terminated_at', 'وقت الإنهاء القسري'),
                    self::dateTime('open_time_finalized_at', 'وقت اعتماد المبلغ النهائي'),
                    TextInput::make('open_time_terminated_by_id')->label('معرف من أنهى الوقت المفتوح')->numeric()->minValue(1),
                    Textarea::make('open_time_termination_reason')->label('سبب إنهاء الوقت المفتوح')->rows(3),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('تفاصيل الخدمة والبيانات الديناميكية')
                ->description('تتيح هذه الحقول تعديل البيانات التفصيلية التي يرسلها التطبيق. يجب إدخال JSON صالح.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    self::jsonField('property_details_json', 'تفاصيل العقار أو المناسبة'),
                    self::jsonField('event_dynamic_answers_json', 'إجابات حقول المناسبة'),
                    self::jsonField('cleaning_services_json', 'خدمات التنظيف'),
                    self::jsonField('open_time_extension_options_json', 'خيارات تمديد الوقت المفتوح'),
                    self::jsonField('worker_finished_cleaning_services_json', 'الخدمات التي أكد العامل إنهاءها'),
                    self::jsonField('worker_finished_property_rooms_json', 'الغرف التي أكد العامل إنهاءها'),
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
                    Textarea::make('cancellation_reason')->label('سبب الإلغاء')->rows(3),
                    Textarea::make('worker_completion_message')->label('ملاحظة العامل عند إنهاء العمل')->rows(3),
                    Textarea::make('customer_completion_rejection_message')->label('سبب رفض العميل إنهاء الطلب')->rows(3),
                    Textarea::make('recurring_pause_reason')->label('سبب إيقاف الحجز الدوري')->rows(3),
                    self::dateTime('recurring_paused_at', 'وقت إيقاف الحجز الدوري'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('أوقات التنفيذ')
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
                    self::dateTime('female_worker_safety_pledge_accepted_at', 'وقت قبول تعهد سلامة العاملات'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    private static function numeric(string $name, string $label, float $step = 0.01): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->step($step)->minValue(0);
    }

    private static function integer(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->step(1)->minValue(0);
    }

    private static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->minValue(0)->step(1)->suffix('ل.س');
    }

    private static function dateTime(string $name, string $label): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->seconds(false)
            ->displayFormat('Y-m-d H:i')
            ->native(false);
    }

    private static function jsonField(string $name, string $label): Textarea
    {
        return Textarea::make($name)
            ->label($label)
            ->rows(8)
            ->rules(['nullable', 'json'])
            ->columnSpanFull();
    }
}
