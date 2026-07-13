<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Schemas;

use App\Enums\DisputeCategory;
use App\Enums\EmergencyType;
use App\Enums\SupportCaseKind;
use App\Models\SupportCase;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class SupportCaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات البلاغ')
                ->schema([
                    TextEntry::make('case_number')->label('رقم البلاغ')->copyable(),
                    TextEntry::make('kind')->label('النوع')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('priority')->label('الأولوية')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('reporter_role')->label('مصدر البلاغ')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('category')->label('التصنيف')->badge()->formatStateUsing(fn ($state, SupportCase $record): string => self::categoryLabel($record, (string) $state)),
                    TextEntry::make('description')->label('تفاصيل البلاغ')->columnSpanFull(),
                    TextEntry::make('worker_earnings_frozen')->label('تجميد مستحقات العامل')->formatStateUsing(fn ($state): string => $state ? 'نعم' : 'لا'),
                    TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d H:i'),
                ])
                ->columns(4),

            Section::make('الحجز والأطراف')
                ->schema([
                    TextEntry::make('booking.booking_number')->label('رقم الحجز')->placeholder('-')->copyable(),
                    TextEntry::make('booking.status')->label('حالة الحجز')->badge()->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
                    TextEntry::make('booking.customer.name')->label('العميل')->placeholder('-'),
                    TextEntry::make('booking.customer.phone')->label('رقم هاتف العميل')->placeholder('-')->copyable(),
                    TextEntry::make('booking.worker.first_name')->label('العامل')->placeholder('-'),
                    TextEntry::make('booking.worker.user.phone')->label('رقم هاتف العامل')->placeholder('-')->copyable(),
                ])
                ->columns(3),

            Section::make('الموقع')
                ->schema([
                    TextEntry::make('latitude')->label('خط العرض')->placeholder('-'),
                    TextEntry::make('longitude')->label('خط الطول')->placeholder('-'),
                    TextEntry::make('location_map')
                        ->label('الموقع على OpenStreetMap')
                        ->html()
                        ->state(fn (SupportCase $record): HtmlString => self::mapHtml($record))
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (SupportCase $record): bool => $record->latitude !== null && $record->longitude !== null),

            Section::make('المرفقات')
                ->schema([
                    TextEntry::make('attachments_list')
                        ->label('')
                        ->html()
                        ->state(fn (SupportCase $record): HtmlString => self::attachmentsHtml($record))
                        ->columnSpanFull(),
                ])
                ->visible(fn (SupportCase $record): bool => $record->getMedia('attachments')->isNotEmpty()),

            Section::make('سجل الرسائل')
                ->schema([
                    RepeatableEntry::make('messages')
                        ->label('')
                        ->schema([
                            TextEntry::make('sender.name')->label('المرسل')->placeholder('-'),
                            TextEntry::make('sender_role')->label('الصفة')->badge()->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
                            TextEntry::make('body')->label('الرسالة')->columnSpan(2),
                            TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                        ])
                        ->columns(4),
                ]),

            Section::make('سجل الإجراءات')
                ->schema([
                    RepeatableEntry::make('events')
                        ->label('')
                        ->schema([
                            TextEntry::make('event_type')->label('الإجراء'),
                            TextEntry::make('actor.name')->label('المنفذ')->placeholder('النظام'),
                            TextEntry::make('from_status')->label('من')->placeholder('-'),
                            TextEntry::make('to_status')->label('إلى')->placeholder('-'),
                            TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                        ])
                        ->columns(5),
                ]),

            Section::make('القرار النهائي')
                ->schema([
                    TextEntry::make('resolution')->label('القرار')->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state),
                    TextEntry::make('resolution_note')->label('ملاحظة القرار')->placeholder('-'),
                    TextEntry::make('resolvedBy.name')->label('تم الحل بواسطة')->placeholder('-'),
                    TextEntry::make('resolved_at')->label('تاريخ الحل')->dateTime('Y-m-d H:i')->placeholder('-'),
                ])
                ->columns(2),
        ]);
    }

    private static function categoryLabel(SupportCase $record, string $category): string
    {
        if ($record->kind === SupportCaseKind::Emergency) {
            return match (EmergencyType::tryFrom($category)) {
                EmergencyType::SafetyThreat => 'تهديد أو عدم أمان',
                EmergencyType::MedicalEmergency => 'حالة طبية طارئة',
                EmergencyType::SevereConflict => 'خلاف حاد',
                default => $category,
            };
        }

        return DisputeCategory::tryFrom($category)?->label() ?? $category;
    }

    private static function mapHtml(SupportCase $record): HtmlString
    {
        if ($record->latitude === null || $record->longitude === null) {
            return new HtmlString('<span>الموقع غير متوفر</span>');
        }

        $lat = (float) $record->latitude;
        $lng = (float) $record->longitude;
        $delta = 0.006;
        $bbox = implode(',', [$lng - $delta, $lat - $delta, $lng + $delta, $lat + $delta]);
        $embed = 'https://www.openstreetmap.org/export/embed.html?bbox='.urlencode($bbox).'&layer=mapnik&marker='.urlencode($lat.','.$lng);
        $open = 'https://www.openstreetmap.org/?mlat='.urlencode((string) $lat).'&mlon='.urlencode((string) $lng).'#map=17/'.$lat.'/'.$lng;

        return new HtmlString(
            '<div style="width:100%;overflow:hidden;border-radius:12px;border:1px solid #374151">'
            .'<iframe src="'.e($embed).'" style="width:100%;height:320px;border:0" loading="lazy"></iframe>'
            .'</div><a href="'.e($open).'" target="_blank" rel="noopener" style="display:inline-block;margin-top:8px;text-decoration:underline">فتح الموقع في OpenStreetMap</a>'
        );
    }

    private static function attachmentsHtml(SupportCase $record): HtmlString
    {
        $items = $record->getMedia('attachments')->map(function ($media): string {
            return '<a href="'.e($media->getUrl()).'" target="_blank" rel="noopener" style="display:inline-block;margin:6px">'
                .'<img src="'.e($media->getUrl()).'" alt="'.e($media->file_name).'" style="width:140px;height:100px;object-fit:cover;border-radius:10px;border:1px solid #374151">'
                .'</a>';
        })->implode('');

        return new HtmlString('<div style="display:flex;flex-wrap:wrap">'.$items.'</div>');
    }
}
