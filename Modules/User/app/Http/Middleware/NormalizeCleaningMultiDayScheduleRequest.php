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

        $propertyType = mb_strtolower((string) $request->input('propertyType'));
        if ($propertyType === '' && is_numeric($request->route('order'))) {
            $propertyType = mb_strtolower((string) CleaningBooking::query()
                ->whereKey((int) $request->route('order'))
                ->value('property_type'));
        }

        $isPreviousWorkers = $request->is('api/v1/user/cleaning/orders/previous-workers');
        if ($propertyType !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE && ! $isPreviousWorkers) {
            throw ValidationException::withMessages([
                'schedule' => ['Multi-day scheduling is available for event assistance bookings only.'],
            ]);
        }

        $sessions = array_map(static fn (array $session): array => [
            'date' => (string) $session['date'],
            'time' => (string) $session['time'],
            'hours' => round((float) $session['hours'], 2),
        ], (array) data_get($validated, 'schedule.sessions', []));

        usort($sessions, static fn (array $a, array $b): int => strcmp($a['date'].' '.$a['time'], $b['date'].' '.$b['time']));

        $slots = [];
        foreach ($sessions as $index => $session) {
            $key = $session['date'].' '.$session['time'];
            if (isset($slots[$key])) {
                throw ValidationException::withMessages([
                    "schedule.sessions.{$index}.time" => ['Duplicate event session date/time is not allowed.'],
                ]);
            }
            $slots[$key] = true;
        }

        $mode = (string) data_get($validated, 'schedule.mode', count($sessions) > 1 ? 'multi_day' : 'single_day');
        if ($mode === 'single_day' && count($sessions) !== 1) {
            throw ValidationException::withMessages([
                'schedule.mode' => ['single_day schedule must contain exactly one session.'],
            ]);
        }
        if ($mode === 'multi_day' && count($sessions) < 2) {
            throw ValidationException::withMessages([
                'schedule.mode' => ['multi_day schedule must contain at least two sessions.'],
            ]);
        }

        $first = $sessions[0];
        $propertyDetails = $request->input('propertyDetails', []);
        $propertyDetails = is_array($propertyDetails) ? $propertyDetails : [];
        // Keep the legacy propertyDetails.hours value within the existing one-day
        // validation contract. The canonical aggregate duration is derived from
        // schedule.sessions and persisted after the booking is created/updated.
        $propertyDetails['hours'] = $first['hours'];

        $request->merge([
            'schedule' => ['mode' => $mode, 'sessions' => $sessions],
            'scheduledDate' => $first['date'],
            'scheduledTime' => $first['time'],
            'propertyDetails' => $propertyDetails,
        ]);

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
