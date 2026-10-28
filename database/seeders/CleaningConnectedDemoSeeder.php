<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamService;
use Modules\Cleaning\Services\SupervisedTeamScheduleService;
use RuntimeException;

/**
 * Repeatable, non-production data shared by the customer, worker and supervisor apps.
 * Do not seed isolated bookings for each identity: all three must see the same IDs.
 */
final class CleaningConnectedDemoSeeder extends Seeder
{
    private const TAG = 'cleaning-connected-demo';

    public function run(): void
    {
        if (config('app.env') === 'production') {
            return;
        }

        $customer = User::query()->where('email', 'user@dllni.sy')->first();
        $supervisor = User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->first();
        $workers = collect(['cleaning.worker@dllni.sy', 'cleaning.worker2@dllni.sy'])
            ->map(fn (string $email) => Worker::query()
                ->whereHas('user', fn ($query) => $query->where('email', $email))->first());
        $billing = CleaningBillingPolicy::query()->where('is_default', true)->first();

        if (! $customer || ! $supervisor || ! $supervisor->hasRole(SupervisedTeamService::ROLE)
            || $workers->contains(null) || ! $billing) {
            throw new RuntimeException('Connected cleaning demo requires user@dllni.sy, supervisor@dllni.sy, both cleaning workers, and the default billing policy. Run DatabaseSeeder in dependency order.');
        }

        DB::transaction(function () use ($customer, $supervisor, $workers, $billing): void {
            $scheduleService = app(SupervisedTeamScheduleService::class);
            foreach ([
                ['number' => 'CLN-LINKED-001', 'status' => 'worker_assigned', 'appointment' => now()->addMinutes(30)->startOfMinute(), 'base' => 200000, 'travel' => 20000, 'workStartedAt' => null],
                ['number' => 'CLN-LINKED-002', 'status' => 'in_progress', 'appointment' => now()->subHour()->startOfMinute(), 'base' => 270000, 'travel' => 30000, 'workStartedAt' => now()->subHour()],
            ] as $scenario) {
                $date = $scenario['appointment']->toDateString();
                $time = $scenario['appointment']->format('H:i');
                $schedule = $scheduleService->estimate(240, 2, $date, $time);
                $scheduledEndAt = CarbonImmutable::parse($schedule['scheduledEndAt']);
                $margin = $scenario['base'] * 0.10;
                $bookingId = $this->upsert('cleaning_bookings', ['booking_number' => $scenario['number']], [
                    'customer_id' => $customer->id,
                    'worker_id' => $workers[0]->id,
                    'preferred_worker_id' => null,
                    'assignment_mode' => 'open_count',
                    'number_of_workers' => 2,
                    'booking_kind' => SupervisedTeamService::KIND,
                    'status' => $scenario['status'],
                    'supervised_state' => $scenario['workStartedAt'] ? 'in_progress' : 'staffing',
                    'supervised_base_minutes' => 240,
                    'supervised_duration_minutes' => $schedule['durationMinutes'],
                    'supervised_scheduled_end_at' => $scheduledEndAt,
                    'supervised_original_end_at' => $scheduledEndAt,
                    'property_type' => 'apartment',
                    'neighborhood_name' => 'الجميلية',
                    'property_details' => json_encode([
                        'location_name' => 'منزل العميل التجريبي في حلب',
                        'address' => 'حلب، الجميلية، قرب ساحة سعد الله الجابري',
                        'bedrooms' => 2, 'bathrooms' => 1, 'kitchens' => 1,
                        'living_room_size' => 'medium',
                        'room_cleaning_modes' => ['bedroom-1' => 'regular', 'kitchen-1' => 'deep'],
                    ], JSON_THROW_ON_ERROR),
                    'cleaning_services' => json_encode(['تنظيف منزلي', 'تنظيف المطبخ'], JSON_THROW_ON_ERROR),
                    'estimated_sqm' => 95,
                    'estimated_hours' => 4,
                    'scheduled_date' => $date,
                    'scheduled_time' => $time,
                    'total_hours' => 4,
                    'base_price' => $scenario['base'],
                    'addons_total' => 0,
                    'travel_fee' => $scenario['travel'],
                    'admin_margin_amount' => $margin,
                    'total_price' => $scenario['base'] + $scenario['travel'] + $margin,
                    'is_pricing_final' => true,
                    'terms_accepted' => true,
                    'billing_policy_id' => $billing->id,
                    'work_started_at' => $scenario['workStartedAt'],
                ]);

                $booking = CleaningBooking::query()->findOrFail($bookingId);
                $scheduleService->initialize($booking, $schedule);
                $booking->forceFill([
                    'supervised_state' => $scenario['workStartedAt'] ? 'in_progress' : 'staffing',
                    'work_started_at' => $scenario['workStartedAt'],
                ])->save();
                DB::table('cleaning_booking_sessions')->where('cleaning_booking_id', $bookingId)->delete();

                $this->upsert('cleaning_supervisor_assignments', ['cleaning_booking_id' => $bookingId, 'active_slot' => 1], [
                    'supervisor_user_id' => $supervisor->id,
                    'assigned_by_user_id' => null,
                    'assigned_at' => now()->subDay(),
                    'released_at' => null,
                    'reason' => 'فريق الاختبار المرتبط بالتطبيقات الثلاثة',
                ]);

                foreach ($workers as $index => $worker) {
                    $share = $scenario['base'] / 2;
                    $travel = $scenario['travel'] / 2;
                    $fee = $margin / 2;
                    $status = $scenario['workStartedAt'] ? 'in_progress' : 'accepted_waiting_for_order_start';
                    $assignmentId = $this->upsert('cleaning_booking_worker_assignments', [
                        'cleaning_booking_id' => $bookingId, 'worker_id' => $worker->id,
                    ], [
                        'status' => $status,
                        'accepted_at' => now()->subDay(),
                        'work_started_at' => $scenario['workStartedAt'],
                        'room_count' => 2,
                        'rooms_weight' => 2,
                        'service_share_amount' => $share,
                        'travel_fee' => $travel,
                        'admin_margin_amount' => $fee,
                        // The customer is charged commission on top of the
                        // service fee; it is not withheld from worker earnings.
                        'worker_amount' => $share + $travel,
                        'currency' => 'SYP',
                    ]);
                }

                foreach ([
                    ['key' => 'bedroom-1', 'type' => 'bedroom', 'label' => 'غرفة النوم الرئيسية', 'size' => 'medium', 'worker' => 0],
                    ['key' => 'bedroom-2', 'type' => 'bedroom', 'label' => 'غرفة النوم الثانية', 'size' => 'small', 'worker' => 0],
                    ['key' => 'kitchen-1', 'type' => 'kitchen', 'label' => 'المطبخ', 'size' => 'medium', 'worker' => 1],
                    ['key' => 'bathroom-1', 'type' => 'bathroom', 'label' => 'الحمام', 'size' => 'small', 'worker' => 1],
                ] as $room) {
                    $this->upsert('cleaning_booking_rooms', [
                        'cleaning_booking_id' => $bookingId, 'room_key' => $room['key'],
                    ], [
                        'room_type' => $room['type'],
                        'room_size' => $room['size'],
                        'display_label' => $room['label'],
                        'weight' => 1,
                        'assigned_worker_id' => null,
                        'assignment_source' => null,
                    ]);
                }

                foreach ([
                    [$customer, 'تم تحديث طلب التنظيف', 'يمكنك متابعة طلبك مع الفريق والمشرف.'],
                    [$supervisor, 'طلب مشترك مسند إليك', 'فريق التنظيف التجريبي مرتبط بهذا الطلب.'],
                    [$workers[0]->user, 'مهمة تنظيف ضمن فريق', 'تم إسناد مهام الغرف إليك تحت إشراف المشرف.'],
                    [$workers[1]->user, 'مهمة تنظيف ضمن فريق', 'تم إسناد مهام الغرف إليك تحت إشراف المشرف.'],
                ] as [$recipient, $title, $body]) {
                    $this->seedNotification($recipient, $scenario['number'], $bookingId, $title, $body);
                }
            }

            $this->seedSessionReportGuardFixture($customer->id, $supervisor->id, $scheduleService);
        });
    }

    private function seedSessionReportGuardFixture(
        int $customerId,
        int $supervisorId,
        SupervisedTeamScheduleService $scheduleService,
    ): void {
        $source = CleaningBooking::query()->where('booking_number', 'CLN-LINKED-002')->firstOrFail();
        $appointment = now()->subHour()->startOfMinute();
        $schedule = $scheduleService->estimate(240, 2, $appointment->toDateString(), $appointment->format('H:i'));
        $fixture = $source->replicate();
        $fixture->forceFill([
            'booking_number' => 'CLN-SESSION-GUARD-001',
            'customer_id' => $customerId,
            'status' => 'awaiting_customer_completion',
            'supervised_state' => 'handover_confirmed',
            'scheduled_date' => $appointment->toDateString(),
            'scheduled_time' => $appointment->format('H:i'),
            'supervised_duration_minutes' => $schedule['durationMinutes'],
            'supervised_scheduled_end_at' => $schedule['scheduledEndAt'],
            'supervised_original_end_at' => $schedule['scheduledEndAt'],
            'customer_confirmed_at' => now(),
        ])->save();
        $scheduleService->initialize($fixture, $schedule);
        $fixture->forceFill([
            'status' => 'awaiting_customer_completion',
            'supervised_state' => 'handover_confirmed',
            'customer_confirmed_at' => now(),
        ])->save();

        $this->upsert('cleaning_supervisor_assignments', [
            'cleaning_booking_id' => $fixture->id, 'active_slot' => 1,
        ], [
            'supervisor_user_id' => $supervisorId,
            'assigned_by_user_id' => null,
            'assigned_at' => now()->subDay(),
            'released_at' => null,
            'reason' => 'جلسة مخصصة للتحقق من رفض التقرير الكلي',
        ]);

        DB::table('cleaning_booking_sessions')->updateOrInsert([
            'cleaning_booking_id' => $fixture->id, 'sequence' => 1,
        ], [
            'scheduled_date' => $appointment->toDateString(),
            'scheduled_time' => $appointment->format('H:i'),
            'duration_hours' => 2,
            'session_type' => 'standard',
            'calculation_mode' => 'fixed',
            'required_workers' => 2,
            'coverage_status' => 'fully_covered',
            'status' => 'completed',
            'base_price' => (int) $fixture->base_price,
            'travel_fee' => (int) $fixture->travel_fee,
            'admin_margin_amount' => (int) $fixture->admin_margin_amount,
            'total_price' => (int) $fixture->total_price,
            'is_pricing_final' => true,
            'payment_status' => 'pending',
            'work_started_at' => $fixture->work_started_at,
            'pricing_snapshot' => json_encode(['source' => self::TAG, 'currency' => 'SYP'], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function seedNotification(User $user, string $number, int $bookingId, string $title, string $body): void
    {
        $key = self::TAG.':'.$number;
        $existing = $user->notifications()->where('data->seedKey', $key)->first();
        $data = [
            'seedTag' => self::TAG, 'seedKey' => $key, 'module' => 'cleaning',
            'type' => 'booking_update', 'title' => $title,
            'body' => $body, 'bookingId' => $bookingId, 'orderId' => $bookingId,
            'bookingNumber' => $number,
        ];
        if ($existing) {
            $existing->forceFill(['data' => $data])->save();
        } else {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'Illuminate\\Notifications\\DatabaseNotification',
                'data' => $data,
                'read_at' => null,
            ]);
        }
    }

    private function upsert(string $table, array $key, array $values): int
    {
        $exists = DB::table($table)->where($key)->exists();
        DB::table($table)->updateOrInsert($key, [
            ...$values, 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()]),
        ]);

        return (int) DB::table($table)->where($key)->value('id');
    }
}
