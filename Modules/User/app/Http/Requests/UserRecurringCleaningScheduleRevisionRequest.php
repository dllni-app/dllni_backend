<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

final class UserRecurringCleaningScheduleRevisionRequest extends FormRequest
{
    private const MAX_WINDOW_DAYS = 30;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule' => ['required', 'array:mode,sessions'],
            'schedule.mode' => ['required', 'string', Rule::in(['recurring'])],
            'schedule.sessions' => ['required', 'array', 'min:1'],
            'schedule.sessions.*' => ['required', 'array:date,time'],
            'schedule.sessions.*.date' => ['required', 'date', 'after_or_equal:'.now(config('app.timezone'))->toDateString()],
            'schedule.sessions.*.time' => ['required', 'date_format:H:i'],
            'schedule.sessions.*.hours' => ['prohibited'],
            'revisionToken' => ['sometimes', 'string', 'size:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sessions = $this->input('schedule.sessions');
            if (! is_array($sessions)) {
                return;
            }

            $slots = [];
            $dates = [];
            $now = CarbonImmutable::now(config('app.timezone'));

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
                    $validator->errors()->add("schedule.sessions.{$index}.time", 'لا يمكن إضافة زيارتين دوريتين في نفس التاريخ والوقت.');
                }
                $slots[$slot] = true;

                try {
                    $startsAt = CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
                    $dates[] = $startsAt->startOfDay();
                    if (! $startsAt->gt($now)) {
                        $validator->errors()->add("schedule.sessions.{$index}.time", 'يجب أن يكون موعد الزيارة المعدلة في المستقبل.');
                    }
                } catch (Throwable) {
                    // Base date/time rules own malformed values.
                }
            }

            if (count($dates) >= 2) {
                usort($dates, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp());
                if ($dates[0]->diffInDays($dates[count($dates) - 1]) > self::MAX_WINDOW_DAYS) {
                    $validator->errors()->add('schedule.sessions', 'يجب أن تقع جميع الزيارات المستقبلية المعدلة ضمن فترة لا تتجاوز 30 يوماً.');
                }
            }
        });
    }
}
