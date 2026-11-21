<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkerComplianceCases\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class WorkerComplianceCaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('worker_compliance.sections.case'))
                ->columns(2)
                ->schema([
                    TextEntry::make('id')->label(__('worker_compliance.fields.case_id')),
                    TextEntry::make('status')
                        ->label(__('worker_compliance.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __('worker_compliance.status.'.$state)),
                    TextEntry::make('worker.first_name')
                        ->label(__('worker_compliance.fields.worker'))
                        ->formatStateUsing(fn (?string $state, $record): string => mb_trim((string) $state.' '.($record->worker->last_name ?? ''))),
                    TextEntry::make('reason_code')->label(__('worker_compliance.fields.reason')),
                    TextEntry::make('cleaning_booking_id')->label(__('worker_compliance.fields.booking_reference'))->placeholder('—'),
                    TextEntry::make('cleaning_booking_session_id')->label(__('worker_compliance.fields.session_reference'))->placeholder('—'),
                    TextEntry::make('observation_started_at')->label(__('worker_compliance.fields.observation_start'))->dateTime(),
                    TextEntry::make('observation_ended_at')->label(__('worker_compliance.fields.observation_end'))->dateTime(),
                    TextEntry::make('sample_count')->label(__('worker_compliance.fields.samples')),
                    TextEntry::make('distance_band')->label(__('worker_compliance.fields.distance_band')),
                    TextEntry::make('accuracy_band')->label(__('worker_compliance.fields.accuracy_band')),
                    TextEntry::make('rule_version')->label(__('worker_compliance.fields.rule_version')),
                    TextEntry::make('claimedBy.name')->label(__('worker_compliance.fields.claimed_by'))->placeholder('—'),
                    TextEntry::make('created_at')->label(__('worker_compliance.fields.opened_at'))->dateTime(),
                ]),
            Section::make(__('worker_compliance.sections.audit'))
                ->schema([
                    RepeatableEntry::make('events')
                        ->label(__('worker_compliance.sections.audit'))
                        ->schema([
                            TextEntry::make('event_type')->label(__('worker_compliance.fields.event')),
                            TextEntry::make('actor.name')->label(__('worker_compliance.fields.actor'))->placeholder('—'),
                            TextEntry::make('from_status')->label(__('worker_compliance.fields.from_status'))->placeholder('—'),
                            TextEntry::make('to_status')->label(__('worker_compliance.fields.to_status'))->placeholder('—'),
                            TextEntry::make('reason')->label(__('worker_compliance.fields.review_reason'))->placeholder('—')->columnSpanFull(),
                            TextEntry::make('corroborating_reference')->label(__('worker_compliance.fields.reference'))->placeholder('—'),
                            TextEntry::make('independent_evidence_reference')->label(__('worker_compliance.fields.independent_evidence'))->placeholder('—'),
                            TextEntry::make('worker_response_opportunity_at')->label(__('worker_compliance.fields.worker_response_opportunity'))->dateTime()->placeholder('—'),
                            TextEntry::make('created_at')->label(__('worker_compliance.fields.event_at'))->dateTime(),
                        ])
                        ->columns(2),
                ]),
        ]);
    }
}
