<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Support;

use App\Models\Worker;
use App\Models\WorkerTrustLog;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

final class AdjustWorkerTrustScoreAction
{
    public static function make(): Action
    {
        return Action::make('adjustTrustScore')
            ->label('تعديل نقاط الثقة')
            ->icon('heroicon-o-shield-check')
            ->color('warning')
            ->modalHeading('تعديل نقاط الثقة')
            ->modalDescription('عدّل درجة ثقة العامل يدوياً. سيتم تسجيل التغيير في سجل الثقة.')
            ->fillForm(fn (Worker $record): array => [
                'trust_score' => (int) $record->trust_score,
                'reason' => '',
            ])
            ->form([
                TextInput::make('trust_score')
                    ->label('درجة الثقة الجديدة')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('/ 100'),
                Textarea::make('reason')
                    ->label('سبب التعديل (اختياري)')
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (Worker $record, array $data): void {
                $scoreBefore = (int) $record->trust_score;
                $scoreAfter = max(0, min(100, (int) $data['trust_score']));

                if ($scoreAfter === $scoreBefore) {
                    Notification::make()
                        ->title('لم يتم تغيير درجة الثقة')
                        ->warning()
                        ->send();

                    return;
                }

                $reasonNote = trim((string) ($data['reason'] ?? ''));
                $reason = $reasonNote !== ''
                    ? 'admin_manual_adjustment: '.$reasonNote
                    : 'admin_manual_adjustment';

                DB::transaction(function () use ($record, $scoreBefore, $scoreAfter, $reason): void {
                    $record->forceFill(['trust_score' => $scoreAfter])->save();

                    WorkerTrustLog::query()->create([
                        'worker_id' => $record->id,
                        'cleaning_booking_id' => null,
                        'reason' => $reason,
                        'score_delta' => $scoreAfter - $scoreBefore,
                        'score_before' => $scoreBefore,
                        'score_after' => $scoreAfter,
                    ]);
                });

                Notification::make()
                    ->title('تم تحديث نقاط الثقة')
                    ->success()
                    ->send();
            });
    }
}
