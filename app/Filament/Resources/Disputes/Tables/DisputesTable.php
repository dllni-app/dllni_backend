<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Tables;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\Dispute;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\HtmlString;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;

final class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')
                    ->label(self::headerLabel(
                        __('cleaning_admin.disputes.fields.ticket_number'),
                        __('cleaning_admin.column_descriptions.ticket_number'),
                    ))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('booking_number')
                    ->label(self::headerLabel(
                        __('cleaning_admin.disputes.fields.booking_number'),
                        __('cleaning_admin.column_descriptions.booking_number'),
                    ))
                    ->getStateUsing(fn ($record) => $record->booking?->booking_number ?? '-')
                    ->placeholder('-'),
                TextColumn::make('customer_phone')
                    ->label('رقم هاتف العميل')
                    ->getStateUsing(fn (Dispute $record): ?string => $record->booking?->customer?->phone)
                    ->placeholder('-')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('worker_phone')
                    ->label('رقم هاتف العامل')
                    ->getStateUsing(fn (Dispute $record): ?string => $record->booking instanceof CleaningBooking
                        ? $record->booking->worker?->user?->phone
                        : null)
                    ->placeholder('-')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('category')
                    ->label(self::headerLabel(
                        __('cleaning_admin.disputes.fields.category'),
                        __('cleaning_admin.column_descriptions.category'),
                    ))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('status')
                    ->label(self::headerLabel(
                        __('cleaning_admin.disputes.fields.status'),
                        __('cleaning_admin.column_descriptions.status'),
                    ))
                    ->badge()
                    ->color(fn ($state): string => self::statusColor($state))
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('resolution')
                    ->label(self::headerLabel(
                        __('cleaning_admin.disputes.fields.resolution'),
                        __('cleaning_admin.column_descriptions.resolution'),
                    ))
                    ->placeholder('-')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('created_at')
                    ->label(self::headerLabel(
                        __('cleaning_admin.disputes.fields.created_at'),
                        __('cleaning_admin.column_descriptions.created_at'),
                    ))
                    ->since()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'booking' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        CleaningBooking::class => ['customer', 'worker.user'],
                        EventBooking::class => ['customer'],
                    ]);
                },
            ]))
            ->filters([
                SelectFilter::make('status')->label(__('cleaning_admin.disputes.fields.status'))->options(collect(DisputeStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('category')->label(__('cleaning_admin.disputes.fields.category'))->options(collect(DisputeCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('resolution')->label(__('cleaning_admin.disputes.fields.resolution'))->options(collect(DisputeResolution::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('cleaning_admin.shared.actions.view'))
                    ->url(fn (Dispute $record): string => DisputeResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label(__('cleaning_admin.shared.actions.edit'))
                    ->url(fn (Dispute $record): string => DisputeResource::getUrl('edit', ['record' => $record])),
            ]);
    }

    private static function statusColor(DisputeStatus|string|null $status): string
    {
        if (is_string($status)) {
            $status = DisputeStatus::tryFrom($status);
        }

        return match ($status) {
            DisputeStatus::Open => 'danger',
            DisputeStatus::UnderReview => 'warning',
            DisputeStatus::Resolved,
            DisputeStatus::Closed => 'success',
            default => 'gray',
        };
    }

    private static function headerLabel(string $label, string $description): HtmlString
    {
        return new HtmlString(
            '<span style="display:flex;flex-direction:column;line-height:1.2;">'
                . '<span style="display:block;font-weight:600;color:inherit;">' . e($label) . '</span>'
                . '<span style="display:block;margin-top:2px;font-size:11px;font-weight:400;color:#9ca3af;">' . e($description) . '</span>'
                . '</span>',
        );
    }
}
