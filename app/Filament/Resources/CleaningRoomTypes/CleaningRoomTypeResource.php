<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningRoomTypes;

use App\Filament\Clusters\CleaningCatalogCluster;
use App\Filament\Resources\CleaningRoomTypes\Pages\CreateCleaningRoomType;
use App\Filament\Resources\CleaningRoomTypes\Pages\EditCleaningRoomType;
use App\Filament\Resources\CleaningRoomTypes\Pages\ListCleaningRoomTypes;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningRoomType;

final class CleaningRoomTypeResource extends Resource
{
    protected static ?string $model = CleaningRoomType::class;

    protected static ?string $cluster = CleaningCatalogCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return 'أنواع الغرف وأسعار الساعة';
    }

    public static function getModelLabel(): string
    {
        return 'نوع غرفة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنواع الغرف';
    }

    public static function canViewAny(): bool
    {
        return self::authorized('pricing.view');
    }

    public static function canCreate(): bool
    {
        return self::authorized('pricing.update');
    }

    public static function canEdit(Model $record): bool
    {
        return self::authorized('pricing.update');
    }

    public static function canDelete(Model $record): bool
    {
        if (! self::authorized('pricing.update')) {
            return false;
        }

        // Real deletion is allowed for catalog records that have never been
        // used. Historical bookings keep their room codes and price snapshots;
        // deactivate an already-used type instead of destroying its meaning.
        if (\Modules\Cleaning\Models\CleaningBookingRoom::query()
            ->where('room_type', $record->code)->exists()) {
            return false;
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('cleaning_booking_room_periods')
            && \Modules\Cleaning\Models\CleaningBookingRoomPeriod::query()
                ->where('room_type', $record->code)->exists()) {
            return false;
        }

        return ! \Modules\Cleaning\Models\CleaningBooking::query()
            ->where('property_details', 'like', '%"'.$record->code.'"%')
            ->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('كتالوج الغرف وأسعار العمل')
                ->description('سعر مستقل لكل ساعة عمل للغرفة في وضع الحوسة والتعزيل. مدة الغرفة المقترحة تحسب آلياً ويمكن تعديلها في الحجز.')
                ->columns(2)
                ->schema([
                    TextInput::make('code')->label('رمز الغرفة في API')->required()->alphaDash()->maxLength(64)->unique(ignoreRecord: true)->disabledOn('edit'),
                    TextInput::make('name')->label('الاسم الظاهر')->required()->maxLength(150),
                    TextInput::make('icon_key')->label('مفتاح الأيقونة')->maxLength(80)->helperText('اختر وصفاً مدعوماً مثل bedroom أو kitchen أو office أو garden أو laundry أو stairs. يظهر رمز معاينة في القائمة؛ الرموز غير المعروفة تظهر بأيقونة افتراضية في التطبيق.'),
                    TextInput::make('sort_order')->label('الترتيب')->integer()->minValue(0)->default(0),
                    TextInput::make('regular_hourly_rate')->label('سعر ساعة الحوسة (ل.س)')->required()->numeric()->minValue(0)->step(0.01),
                    TextInput::make('deep_hourly_rate')->label('سعر ساعة التعزيل (ل.س)')->required()->numeric()->minValue(0)->step(0.01),
                    Toggle::make('is_active')->label('ظاهر في تطبيق المستخدم')->default(true),
                ]),
            Section::make('الدقائق المقترحة لكل حجم')
                ->description('هذه المدد تظهر تلقائياً في التطبيق عند إضافة الغرفة، ويمكن للعميل تعديلها قبل تأكيد السعر.')
                ->columns(2)
                ->schema([
                    TextInput::make('suggested_minutes_by_size.small.regular')->label('صغيرة · حوسة')->integer()->required()->minValue(1)->default(25),
                    TextInput::make('suggested_minutes_by_size.small.deep')->label('صغيرة · تعزيل')->integer()->required()->minValue(1)->default(50),
                    TextInput::make('suggested_minutes_by_size.medium.regular')->label('متوسطة · حوسة')->integer()->required()->minValue(1)->default(35),
                    TextInput::make('suggested_minutes_by_size.medium.deep')->label('متوسطة · تعزيل')->integer()->required()->minValue(1)->default(70),
                    TextInput::make('suggested_minutes_by_size.large.regular')->label('كبيرة · حوسة')->integer()->required()->minValue(1)->default(45),
                    TextInput::make('suggested_minutes_by_size.large.deep')->label('كبيرة · تعزيل')->integer()->required()->minValue(1)->default(90),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            IconColumn::make('icon_key')->label('الأيقونة')->icon(static fn (?string $state): string => match (mb_strtolower(mb_trim($state ?? ''))) {
                'bed', 'bedroom', 'bedroom_parent_outlined' => 'heroicon-o-home-modern',
                'bath', 'bathroom', 'bathtub', 'toilet' => 'heroicon-o-sparkles',
                'kitchen', 'soup_kitchen', 'dining', 'dining_room' => 'heroicon-o-cake',
                'sofa', 'living_room', 'chair' => 'heroicon-o-tv',
                'balcony', 'garden', 'yard' => 'heroicon-o-sun',
                'corridor', 'door', 'meeting_room' => 'heroicon-o-building-office',
                'shed', 'garage', 'storage' => 'heroicon-o-archive-box',
                'study', 'office', 'desk' => 'heroicon-o-computer-desktop',
                'laundry', 'washing_machine' => 'heroicon-o-swatch',
                'stairs', 'staircase' => 'heroicon-o-bars-3-bottom-left',
                default => 'heroicon-o-squares-2x2',
            }),
            TextColumn::make('name')->label('الغرفة')->searchable()->sortable(),
            TextColumn::make('code')->label('الرمز')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('regular_hourly_rate')->label('ساعة الحوسة')->numeric(decimalPlaces: 2)->sortable(),
            TextColumn::make('deep_hourly_rate')->label('ساعة التعزيل')->numeric(decimalPlaces: 2)->sortable(),
            TextColumn::make('sort_order')->label('الترتيب')->sortable()->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('is_active')->label('متاح')->boolean()->sortable(),
        ])->filters([
            TernaryFilter::make('is_active')
                ->label('ظهور النوع في تطبيق المستخدم')
                ->trueLabel('الأنواع الظاهرة')
                ->falseLabel('الأنواع المخفية'),
        ])->defaultSort('sort_order')->recordActions([
            EditAction::make(),
            Action::make('archiveRoom')->label('إخفاء النوع من التطبيق')
                ->icon('heroicon-o-eye-slash')->color('warning')
                ->visible(static fn (CleaningRoomType $record): bool => $record->is_active && self::authorized('pricing.update'))
                ->requiresConfirmation()
                ->action(static fn (CleaningRoomType $record) => $record->update(['is_active' => false])),
            DeleteAction::make()
                ->label('حذف النوع غير المستخدم')
                ->visible(static fn (CleaningRoomType $record): bool => self::canDelete($record))
                ->requiresConfirmation(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningRoomTypes::route('/'),
            'create' => CreateCleaningRoomType::route('/create'),
            'edit' => EditCleaningRoomType::route('/{record}/edit'),
        ];
    }

    private static function authorized(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission) || $user->can('settings.update'));
    }
}
