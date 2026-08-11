<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemUsers\Schemas;

use App\Filament\Resources\SystemUsers\Tables\SystemUsersTable;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SystemUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات المستخدم')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('الاسم'),
                        TextEntry::make('phone')
                            ->label('رقم الهاتف')
                            ->placeholder('-'),
                        TextEntry::make('email')
                            ->label('البريد الإلكتروني')
                            ->placeholder('-'),
                        TextEntry::make('account_type')
                            ->label('نوع الحساب')
                            ->badge()
                            ->state(fn (User $record): string => SystemUsersTable::accountTypeLabel($record))
                            ->color(fn (User $record): string => SystemUsersTable::accountTypeColor($record)),
                        TextEntry::make('is_active')
                            ->label('حالة الحساب')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'فعال' : 'غير فعال')
                            ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                        TextEntry::make('phone_verified_at')
                            ->label('توثيق رقم الهاتف')
                            ->badge()
                            ->state(fn (User $record): string => $record->phone_verified_at !== null ? 'موثّق' : 'غير موثّق')
                            ->color(fn (User $record): string => $record->phone_verified_at !== null ? 'success' : 'warning'),
                        TextEntry::make('created_at')
                            ->label('تاريخ التسجيل')
                            ->dateTime('Y-m-d H:i'),
                        TextEntry::make('updated_at')
                            ->label('آخر تحديث')
                            ->dateTime('Y-m-d H:i'),
                    ]),
            ]);
    }
}
