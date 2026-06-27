<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMemberBonuses\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\CleaningMemberBonusStatus;

final class CleaningMemberBonusInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Member')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('customer.name')->label('Member')->placeholder('-'),
                                TextEntry::make('customer.phone')->label('Phone')->placeholder('-'),
                                TextEntry::make('customer.email')->label('Email')->placeholder('-'),
                            ]),
                    ]),
                Section::make('Loyalty bonus')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('rule.name')->label('Rule')->placeholder('-'),
                                TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn ($state): string => self::statusLabel($state)),
                                TextEntry::make('trigger_type')->label('Trigger type')->badge(),
                                TextEntry::make('earned_hours')->label('Earned hours')->numeric(2),
                                TextEntry::make('required_hours')->label('Required hours')->numeric(2),
                                TextEntry::make('period_months')->label('Period months'),
                                TextEntry::make('reward_type')->label('Reward type')->badge(),
                                TextEntry::make('reward_value')->label('Reward value')->numeric(2),
                                TextEntry::make('created_at')->label('Created at')->dateTime('Y-m-d H:i'),
                                TextEntry::make('qualifying_started_at')->label('Period start')->dateTime('Y-m-d H:i')->placeholder('-'),
                                TextEntry::make('qualifying_ended_at')->label('Period end')->dateTime('Y-m-d H:i')->placeholder('-'),
                                TextEntry::make('activated_at')->label('Activated at')->dateTime('Y-m-d H:i')->placeholder('-'),
                                TextEntry::make('activatedBy.name')->label('Activated by')->placeholder('-'),
                                TextEntry::make('admin_note')->label('Admin note')->placeholder('-')->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function statusLabel(CleaningMemberBonusStatus|string|null $status): string
    {
        $status = $status instanceof CleaningMemberBonusStatus ? $status : CleaningMemberBonusStatus::tryFrom((string) $status);

        return $status?->label() ?? '-';
    }
}
