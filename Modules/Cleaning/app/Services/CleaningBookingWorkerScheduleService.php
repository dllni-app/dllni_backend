<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingWorkerScheduleService
{
    public function __construct(
        private readonly CleaningBookingSchedulePresenter $presenter,
    ) {}

    /** @return array<string, mixed> */
    public function present(CleaningBooking $booking, Worker $worker): array
    {
        return $this->sanitize($this->presenter->present($booking, $worker), $worker);
    }

    /** @return array{wait: array<int>, replace: array<int>, cancel: array<int>} */
    private static function emptyActionWorkerIds(): array
    {
        return [
            CleaningBookingSessionAttendanceService::ACTION_WAIT => [],
            CleaningBookingSessionAttendanceService::ACTION_REPLACE => [],
            CleaningBookingSessionAttendanceService::ACTION_CANCEL => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    private function sanitize(array $schedule, Worker $worker): array
    {
        $decorate = function (mixed $value) use ($worker): mixed {
            if (! is_array($value)) {
                return $value;
            }

            $incidents = data_get($value, 'attendance.incidents', []);
            $incidents = is_array($incidents) ? $incidents : [];
            $ownIncidents = array_values(array_filter(
                $incidents,
                static fn (mixed $item): bool => is_array($item)
                    && (int) ($item['workerId'] ?? 0) === (int) $worker->id,
            ));
            $incident = $ownIncidents[0] ?? null;

            $value['canReportLate'] = false;
            $value['canReportNoTravel'] = false;
            $value['lateWorkerIds'] = [];
            $value['noTravelWorkerIds'] = [];
            $value['reportableLateWorkerIds'] = [];
            $value['reportableNoTravelWorkerIds'] = [];
            $value['allowedAttendanceActions'] = [];
            $value['attendanceActionWorkerIds'] = self::emptyActionWorkerIds();

            $attendance = $value['attendance'] ?? [];
            $attendance = is_array($attendance) ? $attendance : [];
            $attendance['allowedActions'] = [];
            $attendance['actionWorkerIds'] = self::emptyActionWorkerIds();
            $attendance['incidents'] = $ownIncidents;
            $value['attendance'] = $attendance;

            if (! is_array($incident)) {
                $value['workerAttendanceNotice'] = null;

                return $value;
            }

            $resolvedAt = $incident['resolvedAt'] ?? null;
            $isResolved = filled($resolvedAt);
            $isNoTravel = filled($incident['noTravelReportedAt'] ?? null);
            $message = $isResolved
                ? 'تمت معالجة بلاغ الحضور.'
                : ($isNoTravel
                    ? 'أبلغ العميل عن عدم بدء التوجه. ابدأ التوجه الآن لتحديث الحالة.'
                    : 'أبلغ العميل عن تأخر بدء التوجه. ابدأ التوجه الآن لتحديث الحالة.');

            $value['workerAttendanceNotice'] = [
                'type' => $isNoTravel ? 'no_travel' : 'late',
                'message' => $message,
                'action' => $incident['action'] ?? null,
                'note' => $incident['note'] ?? null,
                'resolvedAt' => $resolvedAt,
                'isResolved' => $isResolved,
            ];

            if (! $isResolved) {
                $value['statusLabel'] = $message;
            }

            return $value;
        };

        $sessions = $schedule['sessions'] ?? [];
        if (is_array($sessions)) {
            $schedule['sessions'] = array_map($decorate, $sessions);
        }

        if (array_key_exists('nextSession', $schedule) && $schedule['nextSession'] !== null) {
            $schedule['nextSession'] = $decorate($schedule['nextSession']);
        }

        return $schedule;
    }
}
