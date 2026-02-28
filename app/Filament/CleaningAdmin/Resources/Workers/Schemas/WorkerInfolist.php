<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Workers\Schemas;

use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class WorkerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('الملف الشخصي')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                ImageEntry::make('photo')
                                    ->label('')
                                    ->getStateUsing(fn ($record) => $record->getFirstMediaUrl('avatar') ?: null)
                                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=W&background=random'),
                                Group::make()
                                    ->schema([
                                        TextEntry::make('first_name')->label('الاسم'),
                                        TextEntry::make('user.phone')->label('الهاتف'),
                                        TextEntry::make('average_rating')->label('التقييم العام')->suffix(' / 5'),
                                        TextEntry::make('total_completed_jobs')->label('المهام المنجزة'),
                                        Group::make()
                                            ->schema([
                                                TextEntry::make('is_verified')->label('موثق')->formatStateUsing(fn ($state) => $state ? 'نعم' : 'لا'),
                                                TextEntry::make('is_featured')->label('مميز')->formatStateUsing(fn ($state) => $state ? 'نعم' : 'لا'),
                                            ])
                                            ->columns(2),
                                    ]),
                            ]),
                    ]),
                Section::make('بطاقة نقاط الثقة')
                    ->schema([
                        TextEntry::make('trust_score')
                            ->label('نقاط الثقة')
                            ->suffix(' / 100')
                            ->weight('bold'),
                        RepeatableEntry::make('trustLogs')
                            ->label('سجل التغيّر')
                            ->schema([
                                TextEntry::make('reason')->label('السبب'),
                                TextEntry::make('score_delta')->label('التغيّر')->suffix(' نقطة'),
                                TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(3),
                    ]),
                Section::make('إحصائيات الأداء')
                    ->schema([
                        Grid::make(5)
                            ->schema([
                                TextEntry::make('total_completed_jobs')->label('المهام المكتملة'),
                                TextEntry::make('acceptance_rate')->label('نسبة القبول')->suffix('%'),
                                TextEntry::make('cancellation_rate')->label('نسبة الإلغاء')->suffix('%'),
                                TextEntry::make('average_rating')->label('متوسط التقييم'),
                                TextEntry::make('open_disputes_count')->label('النزاعات المفتوحة'),
                            ]),
                    ]),
                Section::make('مناطق العمل المفضلة')
                    ->schema([
                        RepeatableEntry::make('zones')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')->label('المنطقة'),
                                TextEntry::make('is_active')->label('نشط')->formatStateUsing(fn ($state) => $state ? 'نعم' : 'لا'),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
