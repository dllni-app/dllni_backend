<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Supermarket\Models\SmStoreStaff;

final class SupermarketOwnerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات مالك السوبرماركت')
                ->schema([
                    TextEntry::make('name')->label('الاسم'),
                    TextEntry::make('phone')->label('رقم الهاتف')->placeholder('—'),
                    TextEntry::make('email')->label('البريد الإلكتروني')->placeholder('—'),
                    TextEntry::make('phone_verified_at')
                        ->label('حالة توثيق الهاتف')
                        ->state(fn (User $record): string => $record->phone_verified_at !== null ? 'موثق' : 'غير موثق')
                        ->badge(),
                    TextEntry::make('created_at')->label('تاريخ إنشاء الحساب')->dateTime('Y-m-d H:i'),
                ])
                ->columns(3),
            Section::make('الارتباط بالسوبرماركت')
                ->schema([
                    TextEntry::make('stores_count')
                        ->label('عدد المتاجر')
                        ->state(fn (User $record): int => $record->smStores()->count())
                        ->badge(),
                    TextEntry::make('stores_names')
                        ->label('المتاجر')
                        ->state(fn (User $record): string => $record->smStores()->orderBy('name')->pluck('name')->implode('، ') ?: 'لا يوجد متجر مرتبط')
                        ->columnSpan(2),
                    TextEntry::make('staff_count')
                        ->label('إجمالي الموظفين')
                        ->state(function (User $record): int {
                            $storeIds = $record->smStores()->pluck('id');

                            return $storeIds->isEmpty()
                                ? 0
                                : SmStoreStaff::query()->whereIn('store_id', $storeIds)->count();
                        })
                        ->badge(),
                ])
                ->columns(3),
        ]);
    }
}
