<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningEquipmentReservations;

use App\Filament\Clusters\CleaningMaterialsCluster;
use App\Filament\Resources\CleaningEquipmentReservations\Pages\ListCleaningEquipmentReservations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Services\CleaningEquipmentHandoverService;

final class CleaningEquipmentReservationResource extends Resource
{
    protected static ?string $model = CleaningEquipmentReservation::class;
    protected static ?string $cluster = CleaningMaterialsCluster::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;
    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.equipment_reservations');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.equipment_reservation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.equipment_reservations');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('equipment.asset_code')->label('رمز المعدة')->searchable(),
                TextColumn::make('equipment.name')->label('المعدة')->searchable(),
                TextColumn::make('bookingSpecialService.booking.booking_number')->label('رقم الحجز')->searchable(),
                TextColumn::make('worker.user.name')->label('العامل')->placeholder('—'),
                TextColumn::make('reserved_from')->label('بداية الحجز')->dateTime()->sortable(),
                TextColumn::make('reserved_until')->label('نهاية الحجز')->dateTime()->sortable(),
                TextColumn::make('status')->label('حالة التسليم')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'reserved' => 'محجوزة',
                        'handed_over' => 'سُلّمت للعامل',
                        'acknowledged' => 'استلمها العامل',
                        'return_pending_confirmation' => 'بانتظار اعتماد الإرجاع',
                        'failure_pending_confirmation' => 'إرجاع مع عطل — بانتظار اعتماد',
                        'returned' => 'تم تأكيد الإرجاع',
                        'failed' => 'عطل مؤكد',
                        'released' => 'أُلغي الحجز',
                        default => $state,
                    })->sortable(),
                TextColumn::make('handed_over_at')->label('وقت التسليم')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('acknowledged_at')->label('إقرار العامل')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('returned_at')->label('بلاغ الإرجاع')->dateTime()->placeholder('—'),
                TextColumn::make('return_confirmed_at')->label('تأكيد الإدارة')->dateTime()->placeholder('لم يؤكد')->toggleable(),
                TextColumn::make('failure_reason')->label('ملاحظات العطل')->limit(40)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options([
                    'reserved' => 'محجوزة',
                    'handed_over' => 'سُلّمت',
                    'acknowledged' => 'مستلمة',
                    'return_pending_confirmation' => 'بانتظار تأكيد الإرجاع',
                    'failure_pending_confirmation' => 'بلاغ عطل بانتظار التأكيد',
                    'returned' => 'تم تأكيد الإرجاع',
                    'failed' => 'عطل مؤكد',
                    'released' => 'أُلغي الحجز',
                ]),
            ])
            ->recordActions([
                Action::make('handover')
                    ->label('تسليم المعدة')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('info')
                    ->visible(fn (CleaningEquipmentReservation $record): bool => self::canOperate() && $record->status === 'reserved')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد تسليم المعدة للعامل')
                    ->form([Textarea::make('note')->label('ملاحظات التسليم')->maxLength(2000)])
                    ->action(function (CleaningEquipmentReservation $record, array $data): void {
                        app(CleaningEquipmentHandoverService::class)->handover($record, auth()->user(), (string) ($data['note'] ?? ''));
                        Notification::make()->title('تم تسجيل تسليم المعدة')->success()->send();
                    }),
                Action::make('confirm_return')
                    ->label('اعتماد الإرجاع')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CleaningEquipmentReservation $record): bool => self::canOperate() && in_array(
                        $record->status, ['return_pending_confirmation', 'failure_pending_confirmation'], true
                    ))
                    ->requiresConfirmation()
                    ->modalHeading('فحص المعدة وتأكيد استلامها')
                    ->modalDescription('لن تصبح المعدة متاحة إلا بعد اعتماد الإرجاع. المعدة المبلّغ عن عطلها ستُحوّل للصيانة.')
                    ->form([Textarea::make('note')->label('نتيجة الفحص وملاحظات الإدارة')->required()->maxLength(2000)])
                    ->action(function (CleaningEquipmentReservation $record, array $data): void {
                        app(CleaningEquipmentHandoverService::class)->confirmReturn($record, auth()->user(), (string) $data['note']);
                        Notification::make()->title('تم اعتماد الإرجاع وتحديث حالة المعدة')->success()->send();
                    }),
            ])
            ->defaultSort('reserved_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningEquipmentReservations::route('/')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed('cleaning_bookings.view');
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    private static function canOperate(): bool
    {
        return self::allowed('bookings.update');
    }

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();
        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
