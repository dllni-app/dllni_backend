<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesEventAssistanceSchedule
{
    /** @return array<string, mixed> */
    protected function eventAssistanceScheduleRules(bool $isEventAssistance, bool $requireFutureDate = true): array
    {
        $dateRules = ['required', 'date'];
        if ($requireFutureDate) {
            $dateRules[] = 'after_or_equal:'.now(config('app.timezone'))->toDateString();
        }

        return [
            'schedule' => [Rule::prohibitedIf(! $isEventAssistance), 'sometimes', 'array:mode,sessions'],
            'schedule.mode' => ['required_with:schedule', 'string', Rule::in(['single_day', 'multi_day'])],
            'schedule.sessions' => ['required_with:schedule', 'array', 'min:1'],
            'schedule.sessions.*' => ['required', 'array:date,time,hours'],
            'schedule.sessions.*.date' => $dateRules,
            'schedule.sessions.*.time' => ['required', 'date_format:H:i'],
            'schedule.sessions.*.hours' => ['required', 'numeric', 'min:1', 'max:24'],
        ];
    }

    protected function validateEventAssistanceSchedule(Validator $validator): void
    {
        $schedule = $this->input('schedule');
        if (! is_array($schedule) || ! is_array($schedule['sessions'] ?? null)) {
            return;
        }

        $sessions = $schedule['sessions'];
        $slots = [];

        foreach ($sessions as $index => $session) {
            if (! is_array($session)) {
                continue;
            }

            $date = mb_trim((string) ($session['date'] ?? ''));
            $time = mb_trim((string) ($session['time'] ?? ''));
            if ($date === '' || $time === '') {
                continue;
            }

            $slot = $date.'|'.$time;
            if (isset($slots[$slot])) {
                $validator->errors()->add(
                    "schedule.sessions.{$index}.time",
                    'لا يمكن إضافة جلستين للمناسبة في نفس التاريخ والوقت.',
                );
            }
            $slots[$slot] = true;
        }

        $mode = mb_strtolower(mb_trim((string) ($schedule['mode'] ?? '')));
        $expectedMode = count($sessions) > 1 ? 'multi_day' : 'single_day';
        if ($mode !== '' && $mode !== $expectedMode) {
            $validator->errors()->add(
                'schedule.mode',
                $expectedMode === 'multi_day'
                    ? 'يجب أن يكون نوع الجدول متعدد الأيام عند وجود أكثر من جلسة.'
                    : 'يجب أن يكون نوع الجدول يوماً واحداً عند وجود جلسة واحدة.',
            );
        }
    }
}
