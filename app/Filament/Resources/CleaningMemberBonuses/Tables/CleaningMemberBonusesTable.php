<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMemberBonuses\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningMemberBonusStatus;
use Modules\Cleaning\Models\CleaningMemberBonus;
use Modules\Cleaning\Services\CleaningLoyaltyAutomationService;

final class CleaningMemberBonusesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('customer.name')->label('Member')->searchable()->placeholder('-'),
                TextColumn::make('rule.name')->label('Rule')->searchable()->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state): string => self::statusLabel($state))->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('earned_hours')->label('Earned hours')->numeric(2)->sortable(),
                TextColumn::make('required_hours')->label('Required hours')->numeric(2)->sortable(),
                TextColumn::make('period_months')->label('Period months')->sortable(),
                TextColumn::make('reward_type')->label('Reward type')->badge(),
                TextColumn::make('reward_value')->label('Reward value')->numeric(2)->sortable(),
                TextColumn::make('activatedBy.name')->label('Activated by')->placeholder('-')->toggleable(),
                TextColumn::make('activated_at')->label('Activated at')->dateTime('Y-m-d H:i')->placeholder('-')->toggleable(),
                TextColumn::make('created_at')->label('Created at')->since()->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'rule', 'activatedBy']))
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        CleaningMemberBonusStatus::Pending->value => CleaningMemberBonusStatus::Pending->label(),
                        CleaningMemberBonusStatus::Activated->value => CleaningMemberBonusStatus::Activated->label(),
                        CleaningMemberBonusStatus::Cancelled->value => CleaningMemberBonusStatus::Cancelled->label(),
                    ]),
                Filter::make('pending')
                    ->label('Pending activation')
                    ->query(fn (Builder $query): Builder => $query->where('status', CleaningMemberBonusStatus::Pending->value)),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CleaningMemberBonus $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading('Activate member bonus')
                    ->modalDescription('This will activate the loyalty bonus for the member. It is not activated automatically by the system.')
                    ->form([
                        Textarea::make('admin_note')
                            ->label('Admin note')
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->action(function (CleaningMemberBonus $record, array $data): void {
                        app(CleaningLoyaltyAutomationService::class)->activate(
                            $record,
                            auth()->user(),
                            filled($data['admin_note'] ?? null) ? (string) $data['admin_note'] : null,
                        );

                        Notification::make()
                            ->title('Member bonus activated')
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
            ]);
    }

    private static function statusLabel(CleaningMemberBonusStatus|string|null $status): string
    {
        $status = $status instanceof CleaningMemberBonusStatus ? $status : CleaningMemberBonusStatus::tryFrom((string) $status);

        return $status?->label() ?? '-';
    }

    private static function statusColor(CleaningMemberBonusStatus|string|null $status): string
    {
        $status = $status instanceof CleaningMemberBonusStatus ? $status : CleaningMemberBonusStatus::tryFrom((string) $status);

        return match ($status) {
            CleaningMemberBonusStatus::Pending => 'warning',
            CleaningMemberBonusStatus::Activated => 'success',
            CleaningMemberBonusStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }
}
