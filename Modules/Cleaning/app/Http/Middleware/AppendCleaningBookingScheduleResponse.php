<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Symfony\Component\HttpFoundation\Response;

final class AppendCleaningBookingScheduleResponse
{
    /** @var array<int, CleaningBooking|null> */
    private array $bookingCache = [];

    public function __construct(
        private readonly CleaningBookingSchedulePresenter $presenter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $this->isCleaningApiRequest($request)) {
            return $response;
        }

        $payload = $response->getData(true);

        if ($request->is('api/v1/user/cleaning/orders/estimate-price') && $request->has('schedule.sessions')) {
            $payload = $this->appendEstimateSchedule($request, $payload);
        }

        $workerId = $request->user()?->worker?->id;
        $payload = $this->walk($payload, $workerId !== null ? (int) $workerId : null);
        $response->setData($payload);

        return $response;
    }

    private function isCleaningApiRequest(Request $request): bool
    {
        return $request->is('api/v1/cleaning-bookings*')
            || $request->is('api/v1/cleaning/worker*')
            || $request->is('api/v1/cleaning-time-warnings*')
            || $request->is('api/v1/user/cleaning/orders*');
    }

    private function walk(mixed $value, ?int $workerId): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->walk($child, $workerId);
        }

        $id = $value['id'] ?? null;
        $isBookingShape = is_numeric($id)
            && (
                ($value['propertyType'] ?? null) === 'event_assistance'
                || ($value['property_type'] ?? null) === 'event_assistance'
                || ($value['type'] ?? null) === 'events'
            );

        if (! $isBookingShape) {
            return $value;
        }

        $bookingId = (int) $id;
        if (! array_key_exists($bookingId, $this->bookingCache)) {
            $this->bookingCache[$bookingId] = CleaningBooking::query()
                ->with(['sessions.workerAssignments.worker.user'])
                ->find($bookingId);
        }

        $booking = $this->bookingCache[$bookingId];
        if ($booking instanceof CleaningBooking) {
            $value['schedule'] = $this->presenter->forBooking($booking, $workerId);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function appendEstimateSchedule(Request $request, array $payload): array
    {
        $sessions = $request->input('schedule.sessions', []);
        if (! is_array($sessions) || $sessions === []) {
            return $payload;
        }

        $pricing = is_array($payload['pricing'] ?? null) ? $payload['pricing'] : [];
        $basePrice = (float) ($pricing['basePrice'] ?? 0);
        $totalHours = max(0.0, (float) array_sum(array_map(
            static fn (mixed $session): float => is_array($session) && is_numeric($session['hours'] ?? null) ? (float) $session['hours'] : 0.0,
            $sessions,
        )));

        $sessionPayload = [];
        $remainingBase = $basePrice;
        $count = count($sessions);

        foreach (array_values($sessions) as $index => $session) {
            if (! is_array($session)) {
                continue;
            }

            $hours = (float) ($session['hours'] ?? 0);
            $sessionBase = $index === $count - 1
                ? round(max(0.0, $remainingBase), 2)
                : round($totalHours > 0 ? $basePrice * ($hours / $totalHours) : 0, 2);
            $remainingBase = round($remainingBase - $sessionBase, 2);

            $sessionPayload[] = [
                'sequence' => $index + 1,
                'date' => $session['date'] ?? null,
                'time' => $session['time'] ?? null,
                'hours' => $hours,
                'basePrice' => $sessionBase,
                'travelFee' => 0.0,
                'totalPrice' => $sessionBase,
            ];
        }

        $payload['schedule'] = [
            'mode' => count($sessionPayload) > 1 ? 'multi_day' : 'single_day',
            'daysCount' => count($sessionPayload),
            'totalHours' => round($totalHours, 2),
            'sessions' => $sessionPayload,
        ];

        return $payload;
    }
}
