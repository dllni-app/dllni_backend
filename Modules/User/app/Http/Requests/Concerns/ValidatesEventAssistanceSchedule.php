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

        $scheduleModes = $isEventAssistance
            ? ['single_day', 'multi_day']
            : ['recurring'];

        return [
            // Event assistance keeps its existing explicit execution schedule.
            // For normal cleaning, supplying a multi-day schedule means a recurring
            // booking: each item is one independent execution visit under one parent.
            'schedule' => ['sometimes', 'array:mode,sessions'],
            'schedule.mode' => ['required_with:schedule', 'string', Rule::in($scheduleModes)],
            'schedule.sessions' => [
                'required_with:schedule',
                'array',
                $isEventAssistance ? 'min:1' : 'min:2',
            ],
            'schedule.sessions.*' => [
                'required',
                $isEventAssistance ? 'array:date,time,hours' : 'array:date,time',
            ],
            'schedule.sessions.*.date' => $dateRules,
            'schedule.sessions.*.time' => ['required', 'date_format:H:i'],
            'schedule.sessions.*.hours' => $isEventAssistance
                ? ['required', 'numeric', 'min:1', 'max:24']
                : ['prohibited'],
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
        $isEventAssistance = method_exists($this, 'isEventAssistanceContext')
            ? $this->isEventAssistanceContext()
            : mb_strtolower((string) $this->input('propertyType')) === 'event_assistance';

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
                    $isEventAssistance
                        ? 'لا يمكن إضافة جلستين للمناسبة في نفس التاريخ والوقت.'
                        : 'لا يمكن إضافة زيارتين دوريتين في نفس التاريخ والوقت.',
                );
            }
            $slots[$slot] = true;
        }

        $mode = mb_strtolower(mb_trim((string) ($schedule['mode'] ?? '')));

        if (! $isEventAssistance) {
            if (count($sessions) < 2) {
                $validator->errors()->add(
                    'schedule.sessions',
                    'الحجز الدوري يحتاج إلى زيارتين على الأقل.',
                );
            }

            if ($mode !== '' && $mode !== 'recurring') {
                $validator->errors()->add(
                    'schedule.mode',
                    'يجب أن يكون نوع جدول الحجز الدوري متعدد الأيام.',
                );
            }

            return;
        }

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
