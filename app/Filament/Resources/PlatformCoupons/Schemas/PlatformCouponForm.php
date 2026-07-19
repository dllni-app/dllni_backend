<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\Schemas;

use App\Models\PlatformCoupon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class PlatformCouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات الكوبون')
                ->columns(2)
                ->schema([
                    TextInput::make('code')->label('الرمز')->required()->maxLength(50)->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state))),
                    Select::make('section')->label('القسم')->required()->native(false)->live()->options([
                        PlatformCoupon::SECTION_CLEANING => 'التنظيف',
                        PlatformCoupon::SECTION_RESTAURANT => 'المطاعم',
                        PlatformCoupon::SECTION_SUPERMARKET => 'السوبر ماركت',
                        PlatformCoupon::SECTION_ALL => 'جميع الأقسام',
                    ]),
                    TextInput::make('title_ar')->label('العنوان بالعربية')->required()->maxLength(255),
                    TextInput::make('title_en')->label('العنوان بالإنجليزية')->maxLength(255),
                    Textarea::make('description_ar')->label('الوصف بالعربية')->required()->rows(3)->columnSpanFull(),
                    Textarea::make('description_en')->label('الوصف بالإنجليزية')->rows(3)->columnSpanFull(),
                ]),
            Section::make('قيمة الخصم')
                ->columns(2)
                ->schema([
                    Select::make('discount_type')->label('نوع الخصم')->required()->native(false)->live()->options([
                        PlatformCoupon::DISCOUNT_FIXED => 'مبلغ ثابت',
                        PlatformCoupon::DISCOUNT_PERCENTAGE => 'نسبة مئوية',
                    ]),
                    TextInput::make('discount_value')->label('قيمة الخصم')->required()->numeric()->minValue(0.01)
                        ->maxValue(fn (Get $get): ?int => $get('discount_type') === PlatformCoupon::DISCOUNT_PERCENTAGE ? 100 : null),
                    TextInput::make('max_discount_amount')->label('الحد الأقصى للخصم')->numeric()->minValue(0)
                        ->visible(fn (Get $get): bool => $get('discount_type') === PlatformCoupon::DISCOUNT_PERCENTAGE),
                    TextInput::make('min_order_amount')->label('الحد الأدنى للطلب')->numeric()->minValue(0),
                ]),
            Section::make('المستخدمون والحدود')
                ->columns(2)
                ->schema([
                    Select::make('audience_type')->label('المستفيدون')->required()->native(false)->live()->default(PlatformCoupon::AUDIENCE_ALL_USERS)->options([
                        PlatformCoupon::AUDIENCE_ALL_USERS => 'جميع المستخدمين',
                        PlatformCoupon::AUDIENCE_SPECIFIC_USERS => 'مستخدمون محددون',
                    ]),
                    Select::make('users')->label('المستخدمون')->relationship(
                        name: 'users',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true)->orderBy('name'),
                    )->multiple()->searchable(['name', 'phone'])->preload()
                        ->required(fn (Get $get): bool => $get('audience_type') === PlatformCoupon::AUDIENCE_SPECIFIC_USERS)
                        ->visible(fn (Get $get): bool => $get('audience_type') === PlatformCoupon::AUDIENCE_SPECIFIC_USERS),
                    TextInput::make('total_usage_limit')->label('إجمالي مرات الاستخدام')->numeric()->integer()->minValue(1),
                    TextInput::make('per_user_usage_limit')->label('مرات الاستخدام لكل مستخدم')->numeric()->integer()->minValue(1)->default(1),
                ]),
            Section::make('قيود التنظيف')
                ->description('ترك أي قائمة فارغة يعني أن الكوبون يشمل جميع القيم من ذلك النوع.')
                ->columns(3)
                ->visible(fn (Get $get): bool => in_array($get('section'), [PlatformCoupon::SECTION_CLEANING, PlatformCoupon::SECTION_ALL], true))
                ->schema([
                    Select::make('property_types')->label('أنواع العقارات')->multiple()->native(false)->options([
                        'apartment' => 'شقة', 'villa' => 'فيلا', 'house' => 'منزل', 'office' => 'مكتب', 'studio' => 'استديو',
                        'event_assistance' => 'مساعدة مناسبات',
                    ]),
                    Select::make('cleaning_modes')->label('أنواع التنظيف')->multiple()->native(false)->options([
                        'regular' => 'تنظيف عادي', 'deep' => 'تنظيف عميق',
                    ]),
                    Select::make('event_types')->label('أنواع المناسبات')->multiple()->native(false)->options([
                        'family_dinner' => 'عشاء عائلي', 'birthday' => 'عيد ميلاد', 'large_gathering' => 'تجمع كبير', 'funeral' => 'عزاء',
                    ]),
                ]),
            Section::make('الصلاحية والحالة')
                ->columns(3)
                ->schema([
                    DateTimePicker::make('starts_at')->label('يبدأ في')->seconds(false),
                    DateTimePicker::make('expires_at')->label('ينتهي في')->seconds(false)->after('starts_at'),
                    Toggle::make('is_active')->label('فعال')->default(true),
                ]),
        ]);
    }
}
