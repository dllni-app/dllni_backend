<?php

declare(strict_types=1);

namespace Modules\User\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Services\UserCleaningOrderEstimationService;
use Symfony\Component\HttpFoundation\Response;

final class NormalizeCleaningMultiDayScheduleRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isCleaningScheduleRequest($request) || ! $request->has('schedule')) {
            return $next($request);
        }

        $propertyType = mb_strtolower((string) $request->input('propertyType'));
        if ($propertyType === '' && is_numeric($request->route('order'))) {
            $propertyType = mb_strtolower((string) CleaningBooking::query()
                ->whereKey((int) $request->route('order'))
                ->value('property_type'));
        }

        if ($propertyType !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            return $next($request);
        }

        $validator = Validator::make($request->all(), [
            'schedule' => ['required', 'array:mode,sessions'],
            'schedule.mode' => ['nullable', 'string', 'in:single_day,multi_day'],
            'schedule.sessions' => ['required', 'array', 'min:1', 'max:31'],
            'schedule.sessions.*' => ['required', 'array:date,time,hours'],
            'schedule.sessions.*.date' => ['required', 'date', 'after_or_equal:'.now(config('app.timezone'))->toDateString()],
            'schedule.sessions.*.time' => ['required', 'date_format:H:i'],
            'schedule.sessions.*.hours' => ['required', 'numeric', 'min:1', 'max:24'],
        ]);
        $validated = $validator->validate();

        $sessions = array_map(static fn (array $session): array => [
            'date' => (string) $session['date'],
            'time' => (string) $session['time'],
            'hours' => round((float) $session['hours'], 2),
        ], (array) data_get($validated, 'schedule.sessions', []));

        // Validate against the original client order so error keys keep pointing
        // at the submitted session index. Aggregate related schedule errors before
        // canonical sorting instead of failing on the first issue only.
        $validationErrors = [];
        $slots = [];
        foreach ($sessions as $index => $session) {
            $key = $session['date'].' '.$session['time'];
            if (isset($slots[$key])) {
                $validationErrors["schedule.sessions.{$index}.time"] = ['Duplicate event session date/time is not allowed.'];

                continue;
            }
            $slots[$key] = true;
        }

        $mode = (string) data_get($validated, 'schedule.mode', count($sessions) > 1 ? 'multi_day' : 'single_day');
        if ($mode === 'single_day' && count($sessions) !== 1) {
            $validationErrors['schedule.mode'] = ['single_day schedule must contain exactly one session.'];
        }
        if ($mode === 'multi_day' && count($sessions) < 2) {
            $validationErrors['schedule.mode'] = ['multi_day schedule must contain at least two sessions.'];
        }

        if ($validationErrors !== []) {
            throw ValidationException::withMessages($validationErrors);
        }

        usort($sessions, static fn (array $a, array $b): int => strcmp($a['date'].' '.$a['time'], $b['date'].' '.$b['time']));

        $first = $sessions[0];
        $merge = [
            'schedule' => ['mode' => $mode, 'sessions' => $sessions],
            'scheduledDate' => $first['date'],
            'scheduledTime' => $first['time'],
        ];

        // Store/estimate requests still need the legacy one-day hours field.
        // PATCH requests must stay partial: injecting propertyDetails.hours here
        // makes UserCleaningOrderUpdateRequest treat a schedule-only edit as a
        // full event-details replacement and incorrectly require every event field.
        if (! $request->isMethod('PATCH')) {
            $propertyDetails = $request->input('propertyDetails', []);
            $propertyDetails = is_array($propertyDetails) ? $propertyDetails : [];
            $propertyDetails['hours'] = $first['hours'];
            $merge['propertyDetails'] = $propertyDetails;
        }

        $request->merge($merge);

        return $next($request);
    }

    private function isCleaningScheduleRequest(Request $request): bool
    {
        return $request->is('api/v1/user/cleaning/orders')
            || $request->is('api/v1/user/cleaning/orders/*')
            || $request->is('api/v1/user/cleaning/orders/estimate-price')
            || $request->is('api/v1/user/cleaning/orders/previous-workers');
    }
}
