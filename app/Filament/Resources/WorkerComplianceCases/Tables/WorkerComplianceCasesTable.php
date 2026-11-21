<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkerComplianceCases\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\WorkerComplianceReviewCase;

final class WorkerComplianceCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label(__('worker_compliance.fields.case_id'))->sortable(),
                TextColumn::make('worker.first_name')
                    ->label(__('worker_compliance.fields.worker'))
                    ->formatStateUsing(fn (string $state, WorkerComplianceReviewCase $record): string => mb_trim($state.' '.($record->worker->last_name ?? '')))
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query->whereHas('worker', static fn (Builder $workerQuery): Builder => $workerQuery->where(static fn (Builder $nameQuery): Builder => $nameQuery
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")))),
                TextColumn::make('status')
                    ->label(__('worker_compliance.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'warning',
                        'under_review' => 'info',
                        'explained', 'inconclusive' => 'gray',
                        'confirmed_violation' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __('worker_compliance.status.'.$state)),
                TextColumn::make('reason_code')->label(__('worker_compliance.fields.reason'))->wrap(),
                TextColumn::make('observation_started_at')->label(__('worker_compliance.fields.observation_start'))->dateTime()->sortable(),
                TextColumn::make('observation_ended_at')->label(__('worker_compliance.fields.observation_end'))->dateTime()->sortable(),
                TextColumn::make('sample_count')->label(__('worker_compliance.fields.samples'))->numeric()->sortable(),
                TextColumn::make('created_at')->label(__('worker_compliance.fields.opened_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('worker_compliance.fields.status'))
                    ->options(collect(['new', 'under_review', 'explained', 'inconclusive', 'confirmed_violation'])
                        ->mapWithKeys(static fn (string $status): array => [$status => __('worker_compliance.status.'.$status)])
                        ->all()),
            ])
            ->defaultSort('created_at')
            ->recordUrl(fn (WorkerComplianceReviewCase $record): string => route('filament.admin.resources.worker-compliance-cases.view', ['record' => $record]));
    }
}
