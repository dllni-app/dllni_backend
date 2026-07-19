<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use App\Enums\WorkerPreferredWorkType;
use App\Models\User;
use App\Models\Worker;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
                Section::make(__('cleaning_admin.workers.sections.location'))
                    ->description(app()->isLocale('ar')
                        ? 'يجب حفظ عنوان المنزل والإحداثيات حتى يتمكن العامل من قبول حجوزات التنظيف.'
                        : 'Home address and coordinates are required before the worker can accept cleaning bookings.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('home_address')
                            ->label(__('cleaning_admin.workers.fields.home_address'))
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('home_latitude')
                            ->label(__('cleaning_admin.workers.fields.home_latitude'))
                            ->numeric()
                            ->step('any')
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('home_longitude')
                            ->label(__('cleaning_admin.workers.fields.home_longitude'))
                            ->numeric()
                            ->step('any')
                            ->minValue(-180)
                            ->maxValue(180),
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
