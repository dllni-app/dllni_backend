<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Schemas;

use App\Enums\SOSStatus;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SosAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('SOS Alert Details')
                ->schema([
                    TextEntry::make('id')->label('ID'),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (mixed $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state)),
                    TextEntry::make('source')->label('Source'),
                    TextEntry::make('message')->label('Message')->columnSpanFull(),
                    TextEntry::make('order.order_number')->label('Order')->placeholder('-'),
                    TextEntry::make('user.name')->label('User')->placeholder('-'),
                    TextEntry::make('triggered_at')->label('Triggered at')->dateTime()->placeholder('-'),
                    TextEntry::make('acknowledged_at')->label('Acknowledged at')->dateTime()->placeholder('-'),
                    TextEntry::make('acknowledgedBy.name')->label('Acknowledged by')->placeholder('-'),
                    TextEntry::make('resolved_at')->label('Resolved at')->dateTime()->placeholder('-'),
                    TextEntry::make('resolvedBy.name')->label('Resolved by')->placeholder('-'),
                    TextEntry::make('resolution_note')->label('Resolution note')->columnSpanFull()->placeholder('-'),
                    TextEntry::make('created_at')->label('Created at')->dateTime(),
                    TextEntry::make('updated_at')->label('Updated at')->dateTime(),
                ])
                ->columns(2),
        ]);
    }

    private static function statusLabel(SOSStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return $status?->value ?? '-';
    }

    private static function statusColor(SOSStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            SOSStatus::Pending => 'warning',
            SOSStatus::Acknowledged => 'info',
            SOSStatus::Resolved => 'success',
            SOSStatus::Triggered => 'danger',
            default => 'gray',
        };
    }

    private static function normalizeStatus(SOSStatus|string|null $status): ?SOSStatus
    {
        if ($status instanceof SOSStatus || $status === null) {
            return $status;
        }

        return SOSStatus::tryFrom($status);
    }
}
