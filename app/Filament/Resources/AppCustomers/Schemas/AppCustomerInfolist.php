<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AppCustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات العميل')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('الاسم'),
                        TextEntry::make('email')
                            ->label('البريد الإلكتروني'),
                        TextEntry::make('phone')
                            ->label('رقم الهاتف')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('تاريخ الإنشاء')
                            ->dateTime('Y-m-d H:i'),
                        TextEntry::make('updated_at')
                            ->label('آخر تحديث')
                            ->dateTime('Y-m-d H:i'),
                    ]),
            ]);
    }
}
