<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningOperationalScenarioSeeder extends Seeder
{
    private const MORPH = CleaningBooking::class;

    public function run(): void
    {
        if (! Schema::hasTable('cleaning_bookings')) {
            return;
        }

        $refs = $this->references();
        if ($refs === []) {
            return;
        }

        DB::transaction(function () use ($refs): void {
            $this->seedAutomationRules();
            $this->seedLegacyCatalogRelations($refs);
            $assignmentIds = $this->seedAssignmentsAndRooms($refs);
            $sessionIds = $this->seedSessions($refs, $assignmentIds);
            $this->seedMaterialsAndSpecialServices($refs, $sessionIds);
            $this->seedWorkflowAndFinancialScenarios($refs, $assignmentIds, $sessionIds);
            $this->seedSecurityReviewsAndSupport($refs, $sessionIds);
        });
    }

    private function references(): array
    {
        $numbers = [
            'completed' => 'CLN-QA-1001',
            'extension' => 'CLN-QA-1003',
            'partial' => 'CLN-QA-1004',
            'progress' => 'CLN-QA-1005',
            'assigned' => 'CLN-QA-1006',
            'open' => 'CLN-QA-1007',
            'awaiting' => 'CLN-QA-1008',
            'cancelled' => 'CLN-QA-1009',
        ];

        $bookings = DB::table('cleaning_bookings')
            ->whereIn('booking_number', array_values($numbers))
            ->get()
            ->keyBy('booking_number');

        foreach ($numbers as $number) {
            if (! $bookings->has($number)) {
                return [];
            }
        }

        $worker1 = DB::table('workers')->where('id', $bookings[$numbers['completed']]->worker_id)->first();
        $worker2 = DB::table('workers')->where('is_active', true)
            ->where('id', '!=', $worker1?->id ?? 0)
            ->orderBy('id')
            ->first();
        $admin = DB::table('users')->where('email', 'admin@admin.com')->first();

        if (! $worker1 || ! $worker2 || ! $admin) {
            return [];
        }

        $refs = ['worker1' => $worker1, 'worker2' => $worker2, 'admin' => $admin];
        foreach ($numbers as $key => $number) {
            $refs[$key] = $bookings[$number];
        }

        return $refs;
    }

    private function seedAutomationRules(): void
    {
        $rules = [
            ['تصعيد نداءات SOS الحرجة', 'sos_escalation', ['severity' => 'critical', 'unacknowledged_minutes' => 5], ['notify_admins' => true]],
            ['تنبيه انخفاض مخزون مواد التنظيف', 'low_stock', ['threshold_crossed' => true], ['notify_admins' => true]],
            ['تنبيه تأخر بدء الطلب', 'booking_delay', ['delay_minutes' => 20], ['create_alert' => true, 'notify_customer' => true]],
        ];

        foreach ($rules as [$name, $type, $conditions, $actions]) {
            DB::table('cleaning_automation_rules')->updateOrInsert(
                ['name' => $name, 'type' => $type],
                [
                    'is_active' => true,
                    'conditions' => json_encode($conditions, JSON_THROW_ON_ERROR),
                    'actions' => json_encode($actions, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedLegacyCatalogRelations(array $refs): void
    {
        $materials = DB::table('cleaning_materials')->orderBy('id')->get();
        foreach ($materials as $material) {
            DB::table('cleaning_material_quantity_rules')->updateOrInsert(
                [
                    'cleaning_material_id' => $material->id,
                    'room_type' => null,
                    'room_size' => null,
                    'cleaning_mode' => 'regular',
                ],
                [
                    'quantity_per_room' => 0.100,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $levels = DB::table('cleaning_dirtiness_levels')->orderBy('sort_order')->get();
        $services = DB::table('cleaning_special_services')->orderBy('id')->get();
        foreach ($services as $service) {
            foreach ($levels as $level) {
                DB::table('cleaning_special_service_dirtiness_rules')->updateOrInsert(
                    [
                        'cleaning_special_service_id' => $service->id,
                        'dirtiness_level' => $level->slug,
                    ],
                    [
                        'price_multiplier' => $level->price_multiplier,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
        $this->mapEquipment('sofa-steam', ['EQ-STEAM-001', 'EQ-CARPET-001']);
        $this->mapEquipment('carpet-wash', ['EQ-CARPET-001']);
        $this->mapEquipment('window-cleaning', ['EQ-LADDER-001']);
        $this->mapEquipment('post-renovation', ['EQ-WDV-001', 'EQ-LADDER-001']);
        $this->mapEquipment('oven-interior', ['EQ-STEAM-001']);
        $this->mapEquipment('fridge-interior', ['EQ-STEAM-001']);

        $this->mapEventServices('family_dinner', ['fridge-interior', 'sofa-steam']);
        $this->mapEventServices('birthday', ['sofa-steam', 'window-cleaning']);
        $this->mapEventServices('large_gathering', ['sofa-steam', 'carpet-wash', 'window-cleaning']);
        $this->mapEventServices('funeral', ['sofa-steam', 'carpet-wash']);

        $workerIds = [(int) $refs['worker1']->id, (int) $refs['worker2']->id];
        $serviceIds = DB::table('cleaning_special_services')->orderBy('id')->pluck('id')->take(4)->all();
        foreach ($workerIds as $workerId) {
            foreach ($serviceIds as $serviceId) {
                DB::table('cleaning_worker_special_service_skills')->updateOrInsert(
                    ['worker_id' => $workerId, 'cleaning_special_service_id' => $serviceId],
                    [
                        'is_active' => true,
                        'approved_by_id' => $refs['admin']->id,
                        'approved_at' => now()->subDays(30),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        $equipmentIds = DB::table('cleaning_special_service_equipment')->orderBy('id')->pluck('id')->all();
        foreach ($workerIds as $index => $workerId) {
            foreach (array_slice($equipmentIds, $index, 2) as $equipmentId) {
                DB::table('cleaning_worker_equipment_authorizations')->updateOrInsert(
                    ['worker_id' => $workerId, 'cleaning_special_service_equipment_id' => $equipmentId],
                    [
                        'is_active' => true,
                        'approved_by_id' => $refs['admin']->id,
                        'approved_at' => now()->subDays(21),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    private function mapEquipment(string $serviceSlug, array $assetCodes): void
    {
        $serviceId = DB::table('cleaning_special_services')->where('slug', $serviceSlug)->value('id');
        if (! $serviceId) {
            return;
        }
        foreach ($assetCodes as $assetCode) {
            $equipmentId = DB::table('cleaning_special_service_equipment')->where('asset_code', $assetCode)->value('id');
            if (! $equipmentId) {
                continue;
            }
            DB::table('cleaning_special_service_equipment_map')->updateOrInsert(
                ['cleaning_special_service_id' => $serviceId, 'cleaning_special_service_equipment_id' => $equipmentId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function mapEventServices(string $eventSlug, array $serviceSlugs): void
    {
        $eventId = DB::table('cleaning_event_types')->where('slug', $eventSlug)->value('id');
        if (! $eventId) {
            return;
        }

        foreach ($serviceSlugs as $serviceSlug) {
            $serviceId = DB::table('cleaning_special_services')->where('slug', $serviceSlug)->value('id');
            if (! $serviceId) {
                continue;
            }
            DB::table('cleaning_event_type_special_services')->updateOrInsert(
                ['cleaning_event_type_id' => $eventId, 'cleaning_special_service_id' => $serviceId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function seedAssignmentsAndRooms(array $refs): array
    {
        $w1 = (int) $refs['worker1']->id;
        $w2 = (int) $refs['worker2']->id;
        $ids = [];

        $ids['completed'] = $this->upsert('cleaning_booking_worker_assignments',
            ['cleaning_booking_id' => $refs['completed']->id, 'worker_id' => $w1],
            ['status' => 'completed', 'accepted_at' => now()->subDays(2), 'work_started_at' => now()->subDays(2)->subHours(4), 'work_finished_at' => now()->subDays(2), 'room_count' => 4, 'rooms_weight' => 4.5, 'service_share_amount' => 180, 'travel_fee' => 10, 'admin_margin_amount' => 20, 'worker_amount' => 170, 'currency' => 'SYP']);

        $ids['partial_w1'] = $this->upsert('cleaning_booking_worker_assignments',
            ['cleaning_booking_id' => $refs['partial']->id, 'worker_id' => $w1],
            ['status' => 'completed', 'accepted_at' => now()->subHours(5), 'work_started_at' => now()->subHours(4), 'work_finished_at' => now()->subHour(), 'room_count' => 2, 'rooms_weight' => 2.0, 'service_share_amount' => 90, 'travel_fee' => 12.5, 'admin_margin_amount' => 10, 'worker_amount' => 92.5, 'currency' => 'SYP']);

        $ids['partial_w2'] = $this->upsert('cleaning_booking_worker_assignments',
            ['cleaning_booking_id' => $refs['partial']->id, 'worker_id' => $w2],
            ['status' => 'in_progress', 'accepted_at' => now()->subHours(5), 'work_started_at' => now()->subHours(4), 'room_count' => 2, 'rooms_weight' => 2.5, 'service_share_amount' => 90, 'travel_fee' => 12.5, 'admin_margin_amount' => 10, 'worker_amount' => 92.5, 'currency' => 'SYP']);
        $ids['progress'] = $this->upsert('cleaning_booking_worker_assignments',
            ['cleaning_booking_id' => $refs['progress']->id, 'worker_id' => $w1],
            ['status' => 'in_progress', 'accepted_at' => now()->subHours(3), 'started_travel_at' => now()->subHours(2)->subMinutes(30), 'arrived_at' => now()->subHours(2), 'start_approved_at' => now()->subHours(2)->addMinutes(5), 'work_started_at' => now()->subHours(2)->addMinutes(10), 'room_count' => 3, 'rooms_weight' => 3.5, 'service_share_amount' => 90, 'travel_fee' => 10, 'admin_margin_amount' => 10, 'worker_amount' => 90, 'currency' => 'SYP']);

        $ids['assigned'] = $this->upsert('cleaning_booking_worker_assignments',
            ['cleaning_booking_id' => $refs['assigned']->id, 'worker_id' => $w1],
            ['status' => 'accepted_waiting_for_order_start', 'accepted_at' => now()->subMinutes(40), 'room_count' => 3, 'rooms_weight' => 3.2, 'service_share_amount' => 180, 'travel_fee' => 10, 'admin_margin_amount' => 20, 'worker_amount' => 170, 'currency' => 'SYP']);

        $ids['awaiting'] = $this->upsert('cleaning_booking_worker_assignments',
            ['cleaning_booking_id' => $refs['awaiting']->id, 'worker_id' => $w1],
            ['status' => 'awaiting_start_verification', 'accepted_at' => now()->subHours(2), 'started_travel_at' => now()->subHour(), 'arrived_at' => now()->subMinutes(20), 'room_count' => 3, 'rooms_weight' => 3.0, 'service_share_amount' => 180, 'travel_fee' => 10, 'admin_margin_amount' => 20, 'worker_amount' => 170, 'currency' => 'SYP']);

        $rooms = [
            ['bedroom_1', 'bedroom', 'medium', 'غرفة النوم الرئيسية', 1.2, $w1],
            ['bedroom_2', 'bedroom', 'small', 'غرفة نوم صغيرة', 0.8, $w1],
            ['bathroom_1', 'bathroom', 'medium', 'الحمام', 0.9, $w2],
            ['kitchen_1', 'kitchen', 'medium', 'المطبخ', 1.6, $w2],
        ];
        foreach ($rooms as [$key, $type, $size, $label, $weight, $workerId]) {
            DB::table('cleaning_booking_rooms')->updateOrInsert(
                ['cleaning_booking_id' => $refs['partial']->id, 'room_key' => $key],
                [
                    'room_type' => $type,
                    'room_size' => $size,
                    'display_label' => $label,
                    'weight' => $weight,
                    'assigned_worker_id' => $workerId,
                    'assignment_source' => 'system',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $serviceId = DB::table('cleaning_services')->where('slug', 'standard-apartment-cleaning')->value('id');
        if ($serviceId) {
            DB::table('cleaning_booking_service')->updateOrInsert(
                ['cleaning_booking_id' => $refs['completed']->id, 'cleaning_service_id' => $serviceId],
                ['quantity' => 1, 'unit_price' => 200, 'total_price' => 200, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        return $ids;
    }

    private function seedSessions(array $refs, array $assignmentIds): array
    {
        $sessions = [];
        $sessions['completed'] = $this->session($refs['completed'], 'completed', 'fully_covered', true);
        $sessions['progress'] = $this->session($refs['progress'], 'in_progress', 'fully_covered', true);
        $sessions['assigned'] = $this->session($refs['assigned'], 'worker_assigned', 'fully_covered', false);
        $sessions['awaiting'] = $this->session($refs['awaiting'], 'awaiting_start_verification', 'fully_covered', true);
        $sessions['cancelled'] = $this->session($refs['cancelled'], 'cancelled', 'fully_covered', true);

        DB::table('cleaning_bookings')->where('id', $refs['open']->id)->update([
            'booking_kind' => 'open_time',
            'open_time_expected_max_minutes' => 240,
            'open_time_hard_max_minutes' => 360,
            'open_time_warning_minutes' => 30,
            'open_time_extension_options' => json_encode([30, 60, 90], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $sessions['open'] = $this->session($refs['open'], 'scheduled', 'searching', false, 'open_time');

        $this->sessionAssignment($sessions['completed'], $assignmentIds['completed'], $refs['worker1'], 'completed');
        $this->sessionAssignment($sessions['progress'], $assignmentIds['progress'], $refs['worker1'], 'in_progress');
        $this->sessionAssignment($sessions['assigned'], $assignmentIds['assigned'], $refs['worker1'], 'accepted_waiting_for_order_start');
        $this->sessionAssignment($sessions['awaiting'], $assignmentIds['awaiting'], $refs['worker1'], 'awaiting_start_verification');

        return $sessions;
    }

    private function session(object $booking, string $status, string $coverage, bool $final, string $type = 'standard'): int
    {
        return $this->upsert('cleaning_booking_sessions',
            ['cleaning_booking_id' => $booking->id, 'sequence' => 1],
            [
                'scheduled_date' => $booking->scheduled_date,
                'scheduled_time' => $booking->scheduled_time,
                'duration_hours' => max(1, (float) $booking->total_hours),
                'status' => $status,
                'base_price' => $booking->base_price,
                'travel_fee' => $booking->travel_fee,
                'admin_margin_amount' => 10,
                'extension_fee_total' => 0,
                'cancellation_fee' => $booking->cancellation_fee,
                'total_price' => $booking->total_price,
                'is_pricing_final' => $final,
                'payment_status' => $status === 'completed' ? 'settled' : 'pending',
                'payment_settled_at' => $status === 'completed' ? now()->subDay() : null,
                'session_type' => $type,
                'calculation_mode' => $type === 'open_time' ? 'actual' : 'fixed',
                'required_workers' => max(1, (int) ($booking->number_of_workers ?? 1)),
                'coverage_status' => $coverage,
                'pricing_snapshot' => json_encode(['source' => 'real_life_demo', 'currency' => 'SYP'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }
    private function sessionAssignment(int $sessionId, int $bookingAssignmentId, object $worker, string $status): void
    {
        $active = in_array($status, ['in_progress', 'completed'], true);
        $completed = $status === 'completed';

        $this->upsert('cleaning_booking_session_worker_assignments',
            ['cleaning_booking_session_id' => $sessionId, 'worker_id' => $worker->id],
            [
                'cleaning_booking_worker_assignment_id' => $bookingAssignmentId,
                'status' => $status,
                'accepted_at' => now()->subHours(4),
                'started_travel_at' => $active ? now()->subHours(3) : null,
                'arrived_at' => $active ? now()->subHours(2)->subMinutes(30) : null,
                'start_approved_at' => $active ? now()->subHours(2)->subMinutes(20) : null,
                'work_started_at' => $active ? now()->subHours(2) : null,
                'work_finished_at' => $completed ? now()->subMinutes(10) : null,
                'service_share_amount' => 90,
                'travel_fee' => 10,
                'admin_margin_amount' => 10,
                'worker_amount' => 90,
                'currency' => 'SYP',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function seedMaterialsAndSpecialServices(array $refs, array $sessionIds): void
    {
        $materials = DB::table('cleaning_materials as m')
            ->join('cleaning_material_types as t', 't.id', '=', 'm.cleaning_material_type_id')
            ->join('cleaning_material_units as u', 'u.id', '=', 't.cleaning_material_unit_id')
            ->select('m.id', 'm.name', 't.id as type_id', 't.price_per_unit', 'u.id as unit_id')
            ->whereIn('m.name', ['فلاش منظف أرضيات ليمون 2 لتر', 'قفازات نيتريل - 50 زوج'])
            ->get();

        foreach ($materials as $index => $material) {
            $quantity = $index === 0 ? 0.400 : 0.100;
            $lineId = $this->upsert('cleaning_booking_materials',
                ['cleaning_booking_id' => $refs['completed']->id, 'cleaning_material_id' => $material->id],
                [
                    'cleaning_material_type_id' => $material->type_id,
                    'cleaning_material_unit_id' => $material->unit_id,
                    'quantity' => $quantity,
                    'unit_price' => $material->price_per_unit,
                    'total_price' => round($quantity * (float) $material->price_per_unit, 2),
                    'inventory_status' => 'consumed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach ([['reserved', -$quantity], ['consumed', 0.0]] as [$movement, $delta]) {
                DB::table('cleaning_material_inventory_movements')->updateOrInsert(
                    ['cleaning_booking_material_id' => $lineId, 'movement_type' => $movement],
                    [
                        'cleaning_material_id' => $material->id,
                        'quantity_delta' => $delta,
                        'reference_type' => self::MORPH,
                        'reference_id' => $refs['completed']->id,
                        'note' => $movement === 'reserved' ? 'حجز الكمية لتجهيز الطلب.' : 'تم استهلاك المادة أثناء تنفيذ الخدمة.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        DB::table('cleaning_booking_material_kits')->updateOrInsert(
            ['cleaning_booking_id' => $refs['completed']->id],
            [
                'status' => 'received',
                'prepared_at' => now()->subDays(2)->subHours(5),
                'prepared_by_id' => $refs['admin']->id,
                'received_by_worker_id' => $refs['worker1']->id,
                'received_at' => now()->subDays(2)->subHours(4),
                'notes' => 'عدة تنظيف مجهزة لطلب شقة مكتمل.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $service = DB::table('cleaning_special_services')->where('slug', 'sofa-steam')->first();
        $level = DB::table('cleaning_dirtiness_levels')->where('slug', 'normal')->first();
        if (! $service || ! $level) {
            return;
        }

        $equipment = DB::table('cleaning_special_service_equipment as e')
            ->join('cleaning_special_service_equipment_map as m', 'm.cleaning_special_service_equipment_id', '=', 'e.id')
            ->where('m.cleaning_special_service_id', $service->id)
            ->select('e.id', 'e.name', 'e.asset_code')
            ->get();

        $lineTotal = round(3 * (float) $service->base_unit_price * (float) $level->price_multiplier, 2);
        $specialLineId = $this->upsert('cleaning_booking_special_services',
            ['cleaning_booking_id' => $refs['progress']->id, 'cleaning_special_service_id' => $service->id],
            [
                'cleaning_booking_session_id' => $sessionIds['progress'],
                'assigned_worker_id' => $refs['worker1']->id,
                'service_name' => $service->name,
                'pricing_unit' => $service->pricing_unit,
                'dirtiness_level' => $level->slug,
                'quantity' => 3,
                'base_unit_price' => $service->base_unit_price,
                'price_multiplier' => $level->price_multiplier,
                'total_price' => $lineTotal,
                'equipment_snapshot' => json_encode($equipment, JSON_THROW_ON_ERROR),
                'notes' => 'ثلاث مقاعد في غرفة المعيشة، بقع استخدام يومية.',
                'execution_status' => 'in_progress',
                'started_at' => now()->subMinutes(35),
                'financial_snapshot' => json_encode(['worker_share' => round($lineTotal * 0.65, 2), 'operating_cost' => round($lineTotal * 0.20, 2)], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->upsert('cleaning_booking_special_service_items',
            ['cleaning_booking_special_service_id' => $specialLineId, 'cleaning_dirtiness_level_id' => $level->id],
            [
                'quantity' => 3,
                'dirtiness_level' => $level->slug,
                'price_multiplier' => $level->price_multiplier,
                'base_unit_price' => $service->base_unit_price,
                'total_price' => $lineTotal,
                'notes' => 'تنظيف بالبخار مع معالجة البقع.',
                'before_images' => json_encode([], JSON_THROW_ON_ERROR),
                'after_images' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('cleaning_booking_special_service_sessions')->updateOrInsert(
            ['cleaning_booking_special_service_id' => $specialLineId, 'cleaning_booking_session_id' => $sessionIds['progress']],
            ['created_at' => now(), 'updated_at' => now()],
        );

        $reservedEquipment = $equipment->first();
        if ($reservedEquipment) {
            $start = Carbon::parse($refs['progress']->scheduled_date.' '.$refs['progress']->scheduled_time)->subMinutes(30);
            DB::table('cleaning_equipment_reservations')->updateOrInsert(
                [
                    'cleaning_special_service_equipment_id' => $reservedEquipment->id,
                    'cleaning_booking_special_service_id' => $specialLineId,
                    'reserved_from' => $start,
                ],
                [
                    'cleaning_booking_session_id' => $sessionIds['progress'],
                    'worker_id' => $refs['worker1']->id,
                    'reserved_until' => $start->copy()->addHours(4),
                    'status' => 'handed_over',
                    'handed_over_at' => $start->copy()->addMinutes(10),
                    'acknowledged_at' => $start->copy()->addMinutes(12),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedWorkflowAndFinancialScenarios(array $refs, array $assignmentIds, array $sessionIds): void
    {
        DB::table('cleaning_booking_price_adjustment_requests')->updateOrInsert(
            ['cleaning_booking_id' => $refs['progress']->id, 'worker_id' => $refs['worker1']->id, 'status' => 'pending'],
            [
                'old_total_price' => $refs['progress']->total_price,
                'proposed_total_price' => (float) $refs['progress']->total_price + 50,
                'reason' => 'اتضح عند الوصول أن مساحة المطبخ أكبر من الوصف وتحتاج وقتاً إضافياً.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('cleaning_open_time_extensions')->updateOrInsert(
            ['cleaning_booking_id' => $refs['open']->id, 'idempotency_key' => 'seed-open-time-extension-001'],
            [
                'cleaning_booking_session_id' => $sessionIds['open'],
                'customer_id' => $refs['open']->customer_id,
                'worker_id' => $refs['worker1']->id,
                'requested_minutes' => 60,
                'status' => 'approved',
                'decision_reason' => 'العميل طلب ساعة إضافية لإنهاء المطبخ والشرفات.',
                'decided_at' => now()->subMinutes(15),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $revision = 'seed-schedule-change-'.(string) $refs['assigned']->id;
        $changeId = $this->upsert('cleaning_schedule_change_requests',
            ['revision_token' => $revision],
            [
                'cleaning_booking_id' => $refs['assigned']->id,
                'customer_id' => $refs['assigned']->customer_id,
                'change_type' => 'schedule',
                'status' => 'pending',
                'affected_session_ids' => json_encode([$sessionIds['assigned']], JSON_THROW_ON_ERROR),
                'before_snapshot' => json_encode(['date' => $refs['assigned']->scheduled_date, 'time' => $refs['assigned']->scheduled_time], JSON_THROW_ON_ERROR),
                'proposed_snapshot' => json_encode(['date' => $refs['assigned']->scheduled_date, 'time' => '16:00'], JSON_THROW_ON_ERROR),
                'price_delta' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('cleaning_schedule_change_decisions')->updateOrInsert(
            ['cleaning_schedule_change_request_id' => $changeId, 'worker_id' => $refs['worker1']->id],
            [
                'cleaning_booking_session_worker_assignment_id' => DB::table('cleaning_booking_session_worker_assignments')
                    ->where('cleaning_booking_session_id', $sessionIds['assigned'])
                    ->where('worker_id', $refs['worker1']->id)
                    ->value('id'),
                'decision' => 'accepted',
                'reason' => 'الموعد الجديد مناسب للعامل.',
                'decided_at' => now()->subMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('cleaning_notification_dispatches')->updateOrInsert(
            ['dedupe_key' => 'seed-booking-'.$refs['assigned']->id.'-start-reminder'],
            [
                'cleaning_booking_id' => $refs['assigned']->id,
                'worker_assignment_id' => $assignmentIds['assigned'],
                'recipient_user_id' => $refs['worker1']->user_id,
                'canonical_type' => 'cleaning.booking.start_reminder',
                'scheduled_at_snapshot' => now()->addHour(),
                'due_at' => now()->subMinutes(5),
                'status' => 'sent',
                'attempts' => 1,
                'sent_at' => now()->subMinutes(4),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('cleaning_financial_penalties')->updateOrInsert(
            ['cleaning_booking_id' => $refs['cancelled']->id],
            [
                'worker_id' => $refs['worker1']->id,
                'customer_id' => $refs['cancelled']->customer_id,
                'penalized_role' => 'customer',
                'financial_source' => 'cancellation_fee',
                'amount' => 25,
                'status' => 'active',
                'review_status' => 'needs_review',
                'notes' => 'إلغاء متأخر قبل موعد الخدمة بوقت قصير.',
                'cancellation_reason_snapshot' => 'تغيير مفاجئ في خطة العميل.',
                'cancellation_offset_minutes' => 45,
                'applied_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('cleaning_booking_session_financial_penalties')->updateOrInsert(
            ['reference_key' => 'seed-session-penalty-'.$sessionIds['cancelled']],
            [
                'cleaning_booking_id' => $refs['cancelled']->id,
                'cleaning_booking_session_id' => $sessionIds['cancelled'],
                'worker_id' => $refs['worker1']->id,
                'customer_id' => $refs['cancelled']->customer_id,
                'penalized_role' => 'customer',
                'financial_source' => 'cancellation_fee',
                'amount' => 25,
                'status' => 'active',
                'reason_snapshot' => 'إلغاء الجلسة قبل 45 دقيقة من الموعد.',
                'applied_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function seedSecurityReviewsAndSupport(array $refs, array $sessionIds): void
    {
        $this->seedSecurityCode($refs['awaiting'], $refs['worker1'], $sessionIds['awaiting'], '4821', false);
        $this->seedSecurityCode($refs['completed'], $refs['worker1'], $sessionIds['completed'], '7314', true);

        DB::table('booking_extensions')->updateOrInsert(
            ['booking_id' => $refs['extension']->id, 'booking_type' => self::MORPH],
            [
                'extra_minutes' => 45,
                'status' => 'requested',
                'requested_at' => now()->subMinutes(25),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('booking_reviews')->updateOrInsert(
            ['booking_id' => $refs['completed']->id, 'booking_type' => self::MORPH, 'customer_id' => $refs['completed']->customer_id],
            [
                'rating' => 5,
                'comment' => 'العامل ملتزم بالموعد والتنظيف ممتاز، خصوصاً المطبخ والحمام.',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
        );

        foreach ([
            ['customer_to_worker', 5, 'تعامل ممتاز ونتيجة نظيفة جداً.'],
            ['worker_to_customer', 5, 'العميل متعاون والموقع واضح وجاهز للخدمة.'],
        ] as [$type, $rating, $comment]) {
            DB::table('worker_customer_ratings')->updateOrInsert(
                ['booking_id' => $refs['completed']->id, 'booking_type' => self::MORPH, 'worker_id' => $refs['worker1']->id, 'customer_id' => $refs['completed']->customer_id, 'rating_type' => $type],
                ['cleaning_booking_session_id' => $sessionIds['completed'], 'rating' => $rating, 'comment' => $comment, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $base = Carbon::parse($refs['progress']->scheduled_date.' '.$refs['progress']->scheduled_time);
        foreach ([
            [36.2127, 37.1456, $base->copy()->subMinutes(35)],
            [36.2140, 37.1480, $base->copy()->subMinutes(20)],
            [36.2162, 37.1511, $base->copy()->subMinutes(5)],
        ] as [$lat, $lng, $recordedAt]) {
            DB::table('cleaning_booking_worker_location_points')->updateOrInsert(
                ['cleaning_booking_id' => $refs['progress']->id, 'worker_id' => $refs['worker1']->id, 'recorded_at' => $recordedAt],
                [
                    'cleaning_booking_worker_assignment_id' => DB::table('cleaning_booking_worker_assignments')
                        ->where('cleaning_booking_id', $refs['progress']->id)
                        ->where('worker_id', $refs['worker1']->id)->value('id'),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('cleaning_booking_worker_rejections')->updateOrInsert(
            ['cleaning_booking_id' => $refs['open']->id, 'worker_id' => $refs['worker2']->id],
            ['reason' => 'العامل لديه حجز متداخل في نفس الوقت.', 'rejected_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
        );

        DB::table('sos_alerts')->updateOrInsert(
            ['booking_id' => $refs['progress']->id, 'booking_type' => self::MORPH, 'emergency_type' => 'safety_concern'],
            [
                'user_id' => $refs['progress']->customer_id,
                'message' => 'تم إرسال تنبيه تجريبي بسبب انقطاع الكهرباء أثناء العمل.',
                'source' => 'cleaning_booking',
                'status' => 'acknowledged',
                'latitude' => 36.2145,
                'longitude' => 37.1492,
                'triggered_at' => now()->subMinutes(18),
                'acknowledged_at' => now()->subMinutes(15),
                'acknowledged_by' => $refs['admin']->id,
                'cleaning_booking_session_id' => $sessionIds['progress'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('system_alerts')->updateOrInsert(
            ['booking_id' => $refs['progress']->id, 'booking_type' => self::MORPH, 'alert_type' => 'cleaning_sos'],
            [
                'severity' => 'critical',
                'status' => 'acknowledged',
                'acknowledged_at' => now()->subMinutes(15),
                'acknowledged_by' => $refs['admin']->id,
                'payload' => json_encode(['source' => 'cleaning_sos', 'message' => 'انقطاع كهرباء أثناء العمل'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $disputeId = $this->upsert('disputes',
            ['ticket_number' => 'CLN-DSP-1001'],
            [
                'booking_id' => $refs['partial']->id,
                'booking_type' => self::MORPH,
                'description' => 'العميل أفاد أن جزءاً من الخدمة اكتمل بينما ما زال العامل الثاني يعمل على المطبخ.',
                'category' => 'service_quality',
                'status' => 'under_review',
                'resolution' => null,
                'worker_earnings_frozen' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach ([
            [$refs['partial']->customer_id, 'App\\Models\\User', 'أرغب بمراجعة جودة تنظيف المطبخ قبل إغلاق الطلب.'],
            [$refs['admin']->id, 'App\\Models\\User', 'تم استلام البلاغ وسيتم مراجعته مع العاملين.'],
        ] as [$senderId, $senderType, $body]) {
            DB::table('dispute_messages')->updateOrInsert(
                ['dispute_id' => $disputeId, 'sender_id' => $senderId, 'sender_type' => $senderType, 'body' => $body],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function seedSecurityCode(object $booking, object $worker, int $sessionId, string $code, bool $consumed): void
    {
        DB::table('booking_security_codes')->updateOrInsert(
            ['booking_id' => $booking->id, 'booking_type' => self::MORPH, 'worker_id' => $worker->id],
            [
                'code' => $code,
                'code_hash' => hash_hmac('sha256', $code, (string) config('app.key')),
                'attempts' => $consumed ? 1 : 0,
                'last_attempt_at' => $consumed ? now()->subDay() : null,
                'consumed_at' => $consumed ? now()->subDay() : null,
                'expires_at' => $consumed ? now()->subDay()->addMinutes(15) : now()->addMinutes(30),
                'cleaning_booking_session_id' => $sessionId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function upsert(string $table, array $keys, array $values): int
    {
        DB::table($table)->updateOrInsert($keys, $values);

        return (int) DB::table($table)->where($keys)->value('id');
    }
}
