<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

/**
 * Repeatable, isolated order-lifecycle examples for the demo worker (Rana).
 *
 * This intentionally uses the same CleaningBooking V2 contract for standard
 * rooms, standalone specialist services, event assistance and hourly work.
 * It is demo-only and never runs through production DatabaseSeeder.
 */
final class WorkerOrderJourneySeeder extends Seeder
{
    private const string PREFIX = 'CLN-W1-JOURNEY-';

    public function run(): void
    {
        // CleaningWorkerArabicDataSeeder names cleaning.worker@dllni.sy
        // "رنا أحمد". Some older test DBs only contain WorkerSeeder's
        // worker1@dllni.sy (also Rana), so retain that local fallback.
        $worker = Worker::query()
            ->whereHas('user', fn ($q) => $q->where('email', 'cleaning.worker@dllni.sy'))
            ->first()
            ?? Worker::query()->whereHas('user', fn ($q) => $q->where('email', 'worker1@dllni.sy'))->first();
        $customer = User::query()->where('email', 'cleaning.customer@dllni.sy')->first();
        $billing = CleaningBillingPolicy::query()->where('is_default', true)->first();

        if (! $worker || ! $customer || ! $billing
            || ! Schema::hasTable('cleaning_booking_sessions')
            || ! Schema::hasTable('cleaning_special_services')
            || ! DB::table('cleaning_special_services')->where('slug', 'sofa-steam')->exists()) {
            return;
        }

        DB::transaction(function () use ($worker, $customer, $billing): void {
            foreach (['ROOM', 'SPECIAL', 'EVENT', 'HOURLY'] as $type) {
                foreach ($this->states() as $index => $state) {
                    $this->seedBooking($worker, $customer, $billing, $type, $state, $index);
                }
            }
        });
    }

    /** @return list<string> */
    private function states(): array
    {
        return [
            CleaningBookingStatus::Pending->value,
            CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingStatus::AwaitingStartVerification->value,
            CleaningBookingStatus::InProgress->value,
            CleaningBookingStatus::TimeExtensionRequested->value,
            CleaningBookingStatus::AwaitingCustomerCompletion->value,
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::Cancelled->value,
        ];
    }

    private function seedBooking(Worker $worker, User $customer, CleaningBillingPolicy $billing, string $type, string $status, int $index): void
    {
        $isPending = $status === CleaningBookingStatus::Pending->value;
        $isStarted = in_array($status, ['in_progress', 'time_extension_requested', 'awaiting_customer_completion', 'completed'], true);
        $isFinished = in_array($status, ['awaiting_customer_completion', 'completed'], true);
        $isCompleted = $status === CleaningBookingStatus::Completed->value;
        $isCancelled = $status === CleaningBookingStatus::Cancelled->value;
        $isEvent = $type === 'EVENT';
        $isHourly = $type === 'HOURLY';
        $isSpecial = $type === 'SPECIAL';
        $hours = $isHourly ? 4 : ($isEvent ? 5 : 3);
        $specialUnitPrice = $isSpecial
            ? (float) (DB::table('cleaning_special_services')->where('slug', 'sofa-steam')->value('base_unit_price') ?? 0)
            : 0.0;
        // Specialist line items and booking financial totals must agree.
        $base = $isHourly ? 400 : ($isEvent ? 350 : ($isSpecial ? round(3 * $specialUnitPrice, 2) : 260));
        $travel = $isEvent ? 50 : 30;
        $margin = $isEvent ? 35 : 25;
        $scheduledAt = $isStarted || $isFinished
            ? now()->subHours($status === 'in_progress' ? 2 : 4)
            : now()->addDays($index + 1)->setTime(10 + ($index % 5), 0);

        // A dedicated overdue case reproduces the expired timer without
        // pretending the worker was selected again or automatically completed.
        if ($type === 'HOURLY' && $status === 'in_progress') {
            $scheduledAt = now()->subHours(6);
        }
        $startedAt = $isStarted ? $scheduledAt->copy() : null;
        $finishedAt = $isFinished ? $scheduledAt->copy()->addHours($hours) : null;
        $number = self::PREFIX.$type.'-'.strtoupper($status);
        $details = [
            'location_name' => $isEvent ? 'مساعدة في مناسبة عائلية' : ($isHourly ? 'عمل بالساعة' : ($isSpecial ? 'تنظيف مفروشات متخصص' : 'تنظيف شقة')),
            'address' => 'حلب، الجميلية، قرب ساحة سعد الله الجابري',
            'rooms' => $type === 'ROOM' ? 3 : 0,
            'bedrooms' => $type === 'ROOM' ? 2 : 0,
            'bathrooms' => $type === 'ROOM' ? 1 : 0,
            'kitchens' => $type === 'ROOM' ? 1 : 0,
            'hours' => $hours,
            ...($isEvent ? ['event_type' => 'birthday', 'guests_count' => 25] : []),
        ];
        $eventTypeId = $isEvent && Schema::hasTable('cleaning_event_types')
            ? DB::table('cleaning_event_types')->where('slug', 'birthday')->value('id')
            : null;

        // Demo fixtures describe snapshots across *past* lifecycle states;
        // applying live preferred-worker eligibility/notification observers
        // to historical snapshots would incorrectly dispatch real work.
        $booking = CleaningBooking::query()->firstOrNew(['booking_number' => $number]);
        $booking->forceFill([
                'customer_id' => $customer->id,
                'worker_id' => $isPending ? null : $worker->id,
                'preferred_worker_id' => $worker->id,
                'assignment_mode' => 'preferred_worker',
                'worker_scope' => 'specific',
                'specific_worker_ids' => [$worker->id],
                'number_of_workers' => 1,
                'billing_policy_id' => $billing->id,
                'status' => $status,
                'booking_kind' => $isHourly ? 'open_time' : 'standard',
                'capability_schema_version' => 2,
                'property_type' => $isEvent ? 'event_assistance' : 'apartment',
                'cleaning_event_type_id' => $eventTypeId,
                'event_dynamic_answers' => $isEvent ? ['guest_count' => 25, 'event_type' => 'birthday'] : null,
                'property_details' => $details,
                'cleaning_services' => $type === 'ROOM' ? ['تنظيف غرف وحمام ومطبخ'] : ($isSpecial ? ['غسيل الكنبايات بالبخار'] : []),
                'estimated_hours' => $hours,
                'total_hours' => $hours,
                'scheduled_date' => $scheduledAt->toDateString(),
                'scheduled_time' => $scheduledAt->format('H:i'),
                'base_price' => $base,
                'addons_total' => 0,
                'travel_fee' => $travel,
                'admin_margin_amount' => $margin,
                'total_price' => $base + $travel,
                'is_pricing_final' => ! $isHourly || $isCompleted,
                'open_time_hourly_rate' => $isHourly ? 100 : null,
                'open_time_minimum_minutes' => $isHourly ? 60 : null,
                'open_time_rounding_minutes' => $isHourly ? 15 : null,
                'open_time_expected_max_minutes' => $isHourly ? 240 : null,
                'open_time_hard_max_minutes' => $isHourly ? 360 : null,
                'open_time_warning_minutes' => $isHourly ? 30 : null,
                'open_time_extension_options' => $isHourly ? [30, 60] : null,
                'open_time_actual_minutes' => $isHourly && $isFinished ? $hours * 60 : null,
                'open_time_billable_minutes' => $isHourly && $isFinished ? $hours * 60 : null,
                'open_time_final_amount' => $isHourly && $isCompleted ? $base : null,
                'open_time_finalized_at' => $isHourly && $isCompleted ? $finishedAt : null,
                'work_started_at' => $startedAt,
                'work_finished_at' => $finishedAt,
                'started_travel_at' => $isStarted ? $scheduledAt->copy()->subMinutes(30) : null,
                'arrived_at' => $isStarted ? $scheduledAt->copy()->subMinutes(10) : null,
                'customer_confirmed_at' => $isCompleted ? $finishedAt?->copy()->addMinutes(5) : null,
                'cancelled_at' => $isCancelled ? now() : null,
                'cancellation_reason' => $isCancelled ? 'إلغاء تجريبي لمراجعة رحلة الطلب' : null,
                'address_latitude' => 36.2127,
                'address_longitude' => 37.1456,
                'terms_accepted' => true,
        ])->saveQuietly();

        $this->seedSession($booking, $worker, $status, $hours, $base, $travel, $margin, $scheduledAt, $startedAt, $finishedAt);
        if ($status === CleaningBookingStatus::TimeExtensionRequested->value) {
            $this->seedExtensionRequest($booking, $worker, $base);
        }
        if ($type === 'ROOM') {
            $this->seedRooms($booking, $worker);
        } elseif ($isSpecial) {
            $this->seedSpecialService($booking, $worker, $status);
        }
    }

    private function seedSession(CleaningBooking $booking, Worker $worker, string $status, int $hours, float $base, int $travel, int $margin, $scheduledAt, $startedAt, $finishedAt): void
    {
        $pending = $status === 'pending';
        $cancelled = $status === 'cancelled';
        $assignmentStatus = match ($status) {
            'worker_assigned' => 'accepted_waiting_for_order_start',
            'awaiting_start_verification' => 'awaiting_start_verification',
            'in_progress' => 'in_progress',
            'time_extension_requested' => 'time_extension_requested',
            'awaiting_customer_completion' => 'awaiting_customer_completion',
            'completed' => 'completed',
            default => 'cancelled',
        };
        $sessionStatus = $pending ? 'scheduled' : $status;
        $sessionId = $this->upsert('cleaning_booking_sessions', ['cleaning_booking_id' => $booking->id, 'sequence' => 1], [
            'scheduled_date' => $scheduledAt->toDateString(),
            'scheduled_time' => $scheduledAt->format('H:i'),
            'duration_hours' => $hours,
            'session_type' => $booking->booking_kind === 'open_time' ? 'open_time' : 'standard',
            'calculation_mode' => $booking->booking_kind === 'open_time' ? 'actual' : 'fixed',
            'required_workers' => 1,
            'coverage_status' => $pending ? 'searching' : 'fully_covered',
            'status' => $sessionStatus,
            'base_price' => $base,
            'travel_fee' => $travel,
            'admin_margin_amount' => $margin,
            'total_price' => $base + $travel,
            'is_pricing_final' => (bool) $booking->is_pricing_final,
            'payment_status' => $status === 'completed' ? 'settled' : 'pending',
            'work_started_at' => $startedAt,
            'work_finished_at' => $finishedAt,
            'pricing_snapshot' => json_encode(['source' => 'worker_order_journey_demo', 'currency' => 'SYP'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($pending) {
            return;
        }

        $workerAmount = $base + $travel - $margin;
        $assignmentId = $this->upsert('cleaning_booking_worker_assignments', ['cleaning_booking_id' => $booking->id, 'worker_id' => $worker->id], [
            'status' => $cancelled ? 'cancelled' : $assignmentStatus,
            'accepted_at' => $cancelled ? null : $scheduledAt->copy()->subDay(),
            'work_started_at' => $startedAt,
            'work_finished_at' => $finishedAt,
            'room_count' => $booking->property_type === 'apartment' ? 3 : 0,
            'rooms_weight' => $booking->property_type === 'apartment' ? 3 : 0,
            'service_share_amount' => $base,
            'travel_fee' => $travel,
            'admin_margin_amount' => $margin,
            'worker_amount' => $workerAmount,
            'currency' => 'SYP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->upsert('cleaning_booking_session_worker_assignments', ['cleaning_booking_session_id' => $sessionId, 'worker_id' => $worker->id], [
            'cleaning_booking_worker_assignment_id' => $assignmentId,
            'status' => $cancelled ? 'cancelled' : $assignmentStatus,
            'accepted_at' => $cancelled ? null : $scheduledAt->copy()->subDay(),
            'work_started_at' => $startedAt,
            'work_finished_at' => $finishedAt,
            'service_share_amount' => $base,
            'travel_fee' => $travel,
            'admin_margin_amount' => $margin,
            'worker_amount' => $workerAmount,
            'currency' => 'SYP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRooms(CleaningBooking $booking, Worker $worker): void
    {
        foreach ([
            ['bedroom-1', 'bedroom', 'medium', 'غرفة النوم الرئيسية'],
            ['bathroom-1', 'bathroom', 'small', 'الحمام'],
            ['kitchen-1', 'kitchen', 'medium', 'المطبخ'],
        ] as [$key, $type, $size, $label]) {
            $this->upsert('cleaning_booking_rooms', ['cleaning_booking_id' => $booking->id, 'room_key' => $key], [
                'room_type' => $type,
                'room_size' => $size,
                'display_label' => $label,
                'weight' => 1,
                'assigned_worker_id' => $booking->status === CleaningBookingStatus::Pending ? null : $worker->id,
                'assignment_source' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedSpecialService(CleaningBooking $booking, Worker $worker, string $status): void
    {
        $service = DB::table('cleaning_special_services')->where('slug', 'sofa-steam')->first();
        if (! $service) {
            return;
        }
        $total = round(3 * (float) $service->base_unit_price, 2);
        $this->upsert('cleaning_booking_special_services', [
            'cleaning_booking_id' => $booking->id,
            'cleaning_special_service_id' => $service->id,
        ], [
            'cleaning_booking_session_id' => DB::table('cleaning_booking_sessions')->where('cleaning_booking_id', $booking->id)->value('id'),
            'assigned_worker_id' => $status === 'pending' ? null : $worker->id,
            'service_name' => $service->name,
            'pricing_unit' => $service->pricing_unit,
            'dirtiness_level' => 'normal',
            'quantity' => 3,
            'base_unit_price' => $service->base_unit_price,
            'price_multiplier' => 1,
            'total_price' => $total,
            'notes' => 'غسيل ثلاث مقاعد مع معالجة البقع',
            'execution_status' => $status === 'completed' ? 'completed' : (in_array($status, ['in_progress', 'time_extension_requested', 'awaiting_customer_completion'], true) ? 'in_progress' : 'pending'),
            'started_at' => in_array($status, ['in_progress', 'time_extension_requested', 'awaiting_customer_completion', 'completed'], true) ? $booking->work_started_at : null,
            'completed_at' => $status === 'completed' ? $booking->work_finished_at : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedExtensionRequest(CleaningBooking $booking, Worker $worker, float $base): void
    {
        $warning = \Modules\Cleaning\Models\CleaningTimeWarning::query()->firstOrNew([
            'booking_id' => $booking->id,
            'booking_type' => 'cleaning_booking',
            'worker_id' => $worker->id,
        ]);
        $warning->forceFill([
            'cleaning_booking_session_id' => $booking->sessions()->value('id'),
            'customer_response' => 'extend_time',
            'customer_message' => 'أرغب في تمديد الجلسة نصف ساعة',
            'worker_response' => null,
            'sent_at' => now()->subMinutes(20),
            'customer_responded_at' => now()->subMinutes(15),
            'worker_responded_at' => null,
            'additional_minutes' => 30,
            'quoted_base_amount' => round($base / 6),
            'quoted_admin_margin_amount' => 5,
            'quoted_amount' => round($base / 6) + 5,
            'quoted_currency' => 'SYP',
        ])->saveQuietly();
    }

    /** @param array<string, mixed> $keys @param array<string, mixed> $values */
    private function upsert(string $table, array $keys, array $values): int
    {
        DB::table($table)->updateOrInsert($keys, $values);

        return (int) DB::table($table)->where($keys)->value('id');
    }
}
