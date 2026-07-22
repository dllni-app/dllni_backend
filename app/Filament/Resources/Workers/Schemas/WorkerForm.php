<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use App\Enums\WorkerPreferredWorkType;
use App\Models\User;
use App\Models\Worker;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class WorkerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cleaning_admin.workers.sections.profile'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')
                            ->label(__('cleaning_admin.workers.fields.first_name'))
                            ->validationMessages(['required' => __('validation.required')])
                            ->required(),
                        Select::make('gender')
                            ->label(__('cleaning_admin.workers.fields.gender'))
                            ->options([
                                'male' => __('cleaning_admin.workers.gender_options.male'),
                                'female' => __('cleaning_admin.workers.gender_options.female'),
                            ])
                            ->nullable(),
                        Select::make('preferred_work_type')
                            ->label(__('cleaning_admin.workers.fields.preferred_work_type'))
                            ->options(WorkerPreferredWorkType::options())
                            ->default(WorkerPreferredWorkType::Both->value)
                            ->validationMessages(['required' => __('validation.required')])
                            ->required(),
                        FileUpload::make('avatar_upload')
                            ->label(app()->isLocale('ar') ? 'صورة الملف الشخصي' : 'Profile image')
                            ->helperText(app()->isLocale('ar')
                                ? 'اختياري: ارفع صورة واضحة للعامل. سيتم حفظها كصورة الملف الشخصي.'
                                : 'Optional: upload a clear worker photo. It will be saved as the profile image.')
                            ->image()
                            ->imageEditor()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(4096)
                            ->storeFiles(false)
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->columnSpanFull(),
                        Textarea::make('bio')
                            ->label(__('cleaning_admin.workers.fields.bio'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.metrics'))
                    ->description(app()->isLocale('ar')
                        ? 'يمكن تعديل درجة الثقة يدوياً من 0 إلى 100، وسيتم تسجيل التغيير في سجل الثقة.'
                        : 'Trust score can be adjusted manually from 0 to 100. The change is recorded in the trust log.')
                    ->columns(2)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        TextInput::make('trust_score')
                            ->label(__('cleaning_admin.workers.fields.trust_score'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                    ]),
                Section::make(app()->isLocale('ar') ? 'الإعدادات المالية للعامل' : 'Worker financial settings')
                    ->description(app()->isLocale('ar')
                        ? 'يطبق حد المديونية على هذا العامل فقط، ولا يوجد حد افتراضي عام.'
                        : 'The debt limit applies only to this worker. There is no global default limit.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('worker_debt_limit')
                            ->label(app()->isLocale('ar') ? 'حد المديونية للعامل' : 'Worker debt limit')
                            ->helperText(app()->isLocale('ar')
                                ? 'يبقى الحساب المالي نشطاً ما دامت المديونية أقل من أو تساوي هذا الحد.'
                                : 'The financial account remains active while indebtedness is less than or equal to this limit.')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->required()
                            ->live()
                            ->dehydrated(false),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.location'))
                    ->description(app()->isLocale('ar')
                        ? 'أدخل عنوان منزل العامل ضمن بيانات الملف الشخصي.'
                        : 'Enter the worker home address as part of the profile details.')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->schema([
                        TextInput::make('home_address')
                            ->label(__('cleaning_admin.workers.fields.home_address'))
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make(app()->isLocale('ar') ? 'المعاملة المالية الأولية' : 'Initial financial transaction')
                    ->description(app()->isLocale('ar')
                        ? 'يمكن تسجيل إيداع أو دين إداري مباشرة عند إنشاء العامل. الدين الإداري يضاف إلى رصيد الإيداع مع بقائه معلّماً كدين.'
                        : 'Optionally record a deposit or administration loan while creating the worker. An administration loan is added to the deposit balance and remains marked as a loan.')
                    ->columns(2)
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->schema([
                        Select::make('initial_financial_transaction_type')
                            ->label(app()->isLocale('ar') ? 'نوع المعاملة المالية' : 'Financial transaction type')
                            ->placeholder(app()->isLocale('ar') ? 'بدون معاملة مالية' : 'No financial transaction')
                            ->options([
                                'deposit' => __('cleaning_admin.workers.finance.deposit.label'),
                                'debt' => __('cleaning_finance.debt.label'),
                            ])
                            ->native(false)
                            ->live()
                            ->dehydrated(false),
                        TextInput::make('initial_financial_transaction_amount')
                            ->label(__('cleaning_admin.workers.finance.fields.amount'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required(fn (Get $get): bool => filled($get('initial_financial_transaction_type')))
                            ->visible(fn (Get $get): bool => filled($get('initial_financial_transaction_type')))
                            ->dehydrated(false),
                        Textarea::make('initial_financial_transaction_notes')
                            ->label(__('cleaning_admin.workers.finance.fields.notes'))
                            ->helperText(fn (Get $get): ?string => $get('initial_financial_transaction_type') === 'debt'
                                ? (app()->isLocale('ar')
                                    ? 'سبب الدين الإداري مطلوب، وسيظهر المبلغ داخل رصيد الإيداع مع تنبيه بأنه دين.'
                                    : 'A reason is required. The amount appears in the deposit balance with an administration-loan warning.')
                                : null)
                            ->required(fn (Get $get): bool => $get('initial_financial_transaction_type') === 'debt')
                            ->visible(fn (Get $get): bool => filled($get('initial_financial_transaction_type')))
                            ->rows(3)
                            ->maxLength(1000)
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Placeholder::make('initial_financial_transaction_warning')
                            ->label(app()->isLocale('ar') ? 'تنبيه' : 'Warning')
                            ->content(fn (Get $get): string => (float) ($get('worker_debt_limit') ?? 0) > 0
                                ? (app()->isLocale('ar')
                                    ? 'سيبدأ رصيد الإيداع بصفر، ويمكن للعامل العمل ضمن حد المديونية الفردي المحدد له.'
                                    : 'The deposit balance starts at zero, and the worker may operate within the configured individual debt limit.')
                                : (app()->isLocale('ar')
                                    ? 'بدون إيداع ومع حد مديونية يساوي صفراً، لن تتوفر سعة مالية لقبول طلبات ذات عمولة.'
                                    : 'Without a deposit and with a zero debt limit, there is no financial capacity for bookings with commission.'))
                            ->visible(fn (Get $get): bool => blank($get('initial_financial_transaction_type')))
                            ->extraAttributes([
                                'class' => 'rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-950',
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.account'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('user_phone')
                            ->label(__('cleaning_admin.workers.fields.phone'))
                            ->tel()
                            ->autocomplete('tel')
                            ->placeholder('+9639XXXXXXXX')
                            ->helperText('أدخل رقم هاتف سوري بصيغة دولية مثل +963912345678.')
                            ->extraInputAttributes([
                                'dir' => 'ltr',
                                'inputmode' => 'tel',
                                'pattern' => '^\\+9639[0-9]{8}$',
                            ])
                            ->validationMessages(['required' => __('validation.required')])
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->rules(fn (?Worker $record, string $operation): array => [
                                self::syrianPhoneFormatRule(),
                                self::workerPhoneAvailabilityRule($record, $operation),
                            ])
                            ->dehydrated(false),
                        TextInput::make('user_password')
                            ->label(__('cleaning_admin.workers.fields.password'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->validationMessages(['required' => __('validation.required')])
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->minLength(8)
                            ->dehydrated(false),
                    ]),
            ]);
    }

    private static function syrianPhoneFormatRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            if (! is_string($value) || preg_match('/^\+9639\d{8}$/', mb_trim($value)) !== 1) {
                $fail('أدخل رقم هاتف سوري بصيغة +9639XXXXXXXX، مثل +963912345678.');
            }
        };
    }

    private static function workerPhoneAvailabilityRule(?Worker $record, string $operation): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record, $operation): void {
            if (blank($value)) {
                return;
            }

            $user = User::query()->where('phone', mb_trim((string) $value))->first();

            if (! $user instanceof User) {
                return;
            }

            if ($operation === 'create') {
                if ($user->worker()->exists()) {
                    $fail('رقم الهاتف مرتبط بعامل موجود مسبقاً. افتح سجل العامل الحالي لتعديله.');
                }

                return;
            }

            if ((int) $user->getKey() !== (int) ($record?->user_id ?? 0)) {
                $fail('رقم الهاتف مستخدم لحساب آخر.');
            }
        };
    }
}
