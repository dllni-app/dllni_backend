<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Models\SupportCase;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

final class SupportCaseInfolistSection
{
    public static function make(): Section
    {
        return Section::make('البلاغات والدعم المرتبط')
            ->description('بلاغات الدعم والطوارئ المرتبطة مباشرة بهذا الطلب.')
            ->schema([
                RepeatableEntry::make('supportCases')
                    ->label('')
                    ->schema([
                        TextEntry::make('case_number')
                            ->label('رقم البلاغ')
                            ->copyable()
                            ->url(fn (SupportCase $record): string => SupportCaseResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('kind')
                            ->label('النوع')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state?->value ?? (string) $state),
                        TextEntry::make('priority')
                            ->label('الأولوية')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state?->value ?? (string) $state),
                        TextEntry::make('status')
                            ->label('الحالة')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state?->value ?? (string) $state),
                        TextEntry::make('category')->label('التصنيف')->placeholder('—'),
                        TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('created_at')->label('وقت البلاغ')->dateTime('Y-m-d H:i'),
                    ])
                    ->columns(4),
            ])
            ->visible(fn (Model $record): bool => SupportCaseResource::canViewAny()
                && method_exists($record, 'supportCases')
                && $record->supportCases()->exists());
    }
}
