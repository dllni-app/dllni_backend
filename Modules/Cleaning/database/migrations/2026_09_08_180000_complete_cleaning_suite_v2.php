<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_materials', function (Blueprint $table): void {
            $table->string('image_path', 2048)->nullable()->after('name');
        });

        Schema::create('cleaning_material_type_quantity_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_material_type_id')->constrained('cleaning_material_types')->cascadeOnDelete();
            $table->string('room_type', 32)->nullable();
            $table->string('room_size', 32)->nullable();
            $table->string('cleaning_mode', 32)->nullable();
            $table->decimal('quantity_per_room', 12, 3);
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_admin_resolution')->default(false);
            $table->json('migration_conflict')->nullable();
            $table->timestamps();
            $table->index(['cleaning_material_type_id', 'is_active'], 'clean_mat_type_rule_active_idx');
        });

        Schema::create('cleaning_booking_material_kits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->string('status', 24)->default('preparing');
            $table->timestamp('prepared_at')->nullable();
            $table->unsignedBigInteger('prepared_by_id')->nullable();
            $table->foreignId('received_by_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'clean_mat_kit_status_idx');
        });

        Schema::create('cleaning_special_service_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cleaning_dirtiness_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price_multiplier', 8, 3)->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('cleaning_special_services', function (Blueprint $table): void {
            $table->foreignId('cleaning_special_service_category_id')->nullable()->after('id')
                ->constrained('cleaning_special_service_categories')->nullOnDelete();
            $table->text('description')->nullable()->after('name');
            $table->string('input_type', 32)->default('quantity')->after('pricing_unit');
            $table->string('unit_code', 32)->nullable()->after('input_type');
            $table->boolean('supports_dirtiness')->default(true)->after('base_unit_price');
            $table->string('gender_constraint', 16)->nullable()->after('supports_dirtiness');
            $table->unsignedSmallInteger('estimated_duration_minutes')->default(60)->after('gender_constraint');
            $table->string('worker_pay_mode', 24)->default('percentage')->after('estimated_duration_minutes');
            $table->decimal('worker_pay_value', 12, 3)->default(0)->after('worker_pay_mode');
            $table->string('operating_cost_mode', 24)->default('flat')->after('worker_pay_value');
            $table->decimal('operating_cost_value', 12, 3)->default(0)->after('operating_cost_mode');
            $table->string('travel_fee_mode', 24)->default('flat')->after('operating_cost_value');
            $table->decimal('travel_fee_value', 12, 3)->default(0)->after('travel_fee_mode');
            $table->boolean('requires_before_image')->default(false)->after('travel_fee_value');
            $table->boolean('requires_after_image')->default(false)->after('requires_before_image');
        });

        Schema::create('cleaning_special_service_dirtiness_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_special_service_id')->constrained('cleaning_special_services')->cascadeOnDelete();
            $table->foreignId('cleaning_dirtiness_level_id')->constrained('cleaning_dirtiness_levels')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cleaning_special_service_id', 'cleaning_dirtiness_level_id'], 'clean_special_global_dirt_unique');
        });

        Schema::table('cleaning_special_service_equipment', function (Blueprint $table): void {
            $table->string('asset_code', 64)->nullable()->unique()->after('name');
            $table->string('status', 24)->default('available')->after('asset_code');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0)->after('status');
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0)->after('buffer_before_minutes');
            $table->timestamp('last_handed_over_at')->nullable();
            $table->timestamp('last_returned_at')->nullable();
        });

        Schema::table('cleaning_booking_special_services', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_session_id')->nullable()->after('cleaning_booking_id')
                ->constrained('cleaning_booking_sessions')->nullOnDelete();
            $table->foreignId('assigned_worker_id')->nullable()->after('cleaning_special_service_id')
                ->constrained('workers')->nullOnDelete();
            $table->string('execution_status', 24)->default('pending')->after('notes');
            $table->text('unable_reason')->nullable()->after('execution_status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('financial_snapshot')->nullable();
        });

        Schema::create('cleaning_booking_special_service_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_special_service_id')->constrained('cleaning_booking_special_services')->cascadeOnDelete();
            $table->foreignId('cleaning_dirtiness_level_id')->nullable()->constrained('cleaning_dirtiness_levels')->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('dirtiness_level', 64)->nullable();
            $table->decimal('price_multiplier', 8, 3)->default(1);
            $table->decimal('base_unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->text('notes')->nullable();
            $table->json('before_images')->nullable();
            $table->json('after_images')->nullable();
            $table->timestamps();
        });

        Schema::create('cleaning_booking_special_service_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_special_service_id')->constrained('cleaning_booking_special_services')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')->constrained('cleaning_booking_sessions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cleaning_booking_special_service_id', 'cleaning_booking_session_id'], 'clean_book_special_session_unique');
        });

        Schema::create('cleaning_worker_special_service_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('cleaning_special_service_id')->constrained('cleaning_special_services')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['worker_id', 'cleaning_special_service_id'], 'clean_worker_special_skill_unique');
        });

        Schema::create('cleaning_worker_equipment_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('cleaning_special_service_equipment_id')->constrained('cleaning_special_service_equipment')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['worker_id', 'cleaning_special_service_equipment_id'], 'clean_worker_equipment_auth_unique');
        });

        Schema::create('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_special_service_equipment_id')->constrained('cleaning_special_service_equipment')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_special_service_id')->constrained('cleaning_booking_special_services')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')->nullable()->constrained('cleaning_booking_sessions')->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->timestamp('reserved_from');
            $table->timestamp('reserved_until');
            $table->string('status', 24)->default('reserved');
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['cleaning_special_service_equipment_id', 'reserved_from', 'reserved_until'], 'clean_equipment_reservation_window_idx');
        });

        Schema::create('cleaning_event_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cleaning_event_type_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_event_type_id')->constrained('cleaning_event_types')->cascadeOnDelete();
            $table->string('label');
            $table->string('key', 64);
            $table->string('field_type', 24);
            $table->boolean('is_required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['cleaning_event_type_id', 'key'], 'clean_event_field_key_unique');
        });

        Schema::create('cleaning_event_type_special_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_event_type_id')->constrained('cleaning_event_types')->cascadeOnDelete();
            $table->foreignId('cleaning_special_service_id')->constrained('cleaning_special_services')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cleaning_event_type_id', 'cleaning_special_service_id'], 'clean_event_special_unique');
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->foreignId('cleaning_event_type_id')->nullable()->after('property_type')
                ->constrained('cleaning_event_types')->nullOnDelete();
            $table->json('event_dynamic_answers')->nullable()->after('property_details');
            $table->unsignedSmallInteger('open_time_expected_max_minutes')->nullable()->after('open_time_rounding_minutes');
            $table->unsignedSmallInteger('open_time_hard_max_minutes')->nullable()->after('open_time_expected_max_minutes');
            $table->unsignedSmallInteger('open_time_warning_minutes')->nullable()->after('open_time_hard_max_minutes');
            $table->json('open_time_extension_options')->nullable()->after('open_time_warning_minutes');
            $table->timestamp('open_time_ceiling_ends_at')->nullable()->after('open_time_extension_options');
            $table->timestamp('open_time_end_requested_at')->nullable()->after('open_time_ceiling_ends_at');
            $table->string('open_time_end_status', 24)->nullable()->after('open_time_end_requested_at');
            $table->timestamp('open_time_terminated_at')->nullable()->after('open_time_end_status');
            $table->unsignedBigInteger('open_time_terminated_by_id')->nullable()->after('open_time_terminated_at');
            $table->text('open_time_termination_reason')->nullable()->after('open_time_terminated_by_id');
            $table->unsignedSmallInteger('capability_schema_version')->default(2)->after('booking_kind');
        });

        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('open_time_expected_max_minutes')->nullable()->after('duration_hours');
            $table->unsignedSmallInteger('open_time_hard_max_minutes')->nullable()->after('open_time_expected_max_minutes');
            $table->timestamp('open_time_ceiling_ends_at')->nullable()->after('open_time_hard_max_minutes');
            $table->timestamp('open_time_end_requested_at')->nullable()->after('open_time_ceiling_ends_at');
            $table->string('open_time_end_status', 24)->nullable()->after('open_time_end_requested_at');
            $table->text('open_time_termination_reason')->nullable()->after('open_time_end_status');
            $table->string('travel_fee_mode', 24)->nullable()->after('travel_fee');
            $table->decimal('travel_fee_value', 12, 3)->nullable()->after('travel_fee_mode');
        });

        Schema::create('cleaning_open_time_extensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')->nullable()->constrained('cleaning_booking_sessions')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->unsignedSmallInteger('requested_minutes');
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key', 100)->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('conflict_snapshot')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['cleaning_booking_id', 'idempotency_key'], 'clean_open_extension_idempotent_unique');
            $table->index(['worker_id', 'status'], 'clean_open_extension_worker_status_idx');
        });

        Schema::create('cleaning_schedule_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('change_type', 32)->default('schedule');
            $table->string('status', 24)->default('pending');
            $table->string('revision_token', 64)->unique();
            $table->json('affected_session_ids');
            $table->json('before_snapshot');
            $table->json('proposed_snapshot');
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->string('customer_resolution', 24)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['cleaning_booking_id', 'status'], 'clean_change_booking_status_idx');
        });

        Schema::create('cleaning_schedule_change_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_schedule_change_request_id')->constrained('cleaning_schedule_change_requests')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_worker_assignment_id')->nullable()
                ->constrained('cleaning_booking_session_worker_assignments')->nullOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('decision', 16)->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['cleaning_schedule_change_request_id', 'worker_id'], 'clean_change_worker_unique');
        });

        $this->backfillMaterialTypeRules();
        $this->backfillDirtinessLevels();
        $this->seedEventTypes();
        $this->backfillBookingEventTypes();

        DB::table('cleaning_bookings')
            ->where('booking_kind', 'open_time')
            ->whereNull('open_time_expected_max_minutes')
            ->update([
                'open_time_expected_max_minutes' => 480,
                'open_time_hard_max_minutes' => 480,
                'open_time_warning_minutes' => 30,
                'open_time_extension_options' => json_encode([15, 30, 60], JSON_THROW_ON_ERROR),
                'capability_schema_version' => 2,
            ]);
    }

    private function backfillMaterialTypeRules(): void
    {
        $rules = DB::table('cleaning_material_quantity_rules as rules')
            ->join('cleaning_materials as materials', 'materials.id', '=', 'rules.cleaning_material_id')
            ->select([
                'materials.cleaning_material_type_id',
                'rules.room_type',
                'rules.room_size',
                'rules.cleaning_mode',
                'rules.quantity_per_room',
                'rules.is_active',
            ])
            ->orderBy('rules.id')
            ->get()
            ->groupBy(fn (object $rule): string => implode('|', [
                $rule->cleaning_material_type_id,
                $rule->room_type ?? '*',
                $rule->room_size ?? '*',
                $rule->cleaning_mode ?? '*',
            ]));

        foreach ($rules as $group) {
            $first = $group->first();
            $quantities = $group->pluck('quantity_per_room')->map(static fn (mixed $value): string => (string) $value)->unique()->values();
            $conflict = $quantities->count() > 1;
            DB::table('cleaning_material_type_quantity_rules')->insert([
                'cleaning_material_type_id' => $first->cleaning_material_type_id,
                'room_type' => $first->room_type,
                'room_size' => $first->room_size,
                'cleaning_mode' => $first->cleaning_mode,
                'quantity_per_room' => $first->quantity_per_room,
                'is_active' => ! $conflict && (bool) $first->is_active,
                'requires_admin_resolution' => $conflict,
                'migration_conflict' => $conflict ? json_encode(['quantities' => $quantities->all()], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillDirtinessLevels(): void
    {
        $rules = DB::table('cleaning_special_service_dirtiness_rules')->orderBy('id')->get();
        foreach ($rules as $rule) {
            $slug = mb_strtolower(mb_trim((string) $rule->dirtiness_level));
            $slug = preg_replace('/[^a-z0-9_-]+/u', '-', $slug) ?: 'level-'.$rule->id;
            $existing = DB::table('cleaning_dirtiness_levels')
                ->where('slug', $slug)
                ->where('price_multiplier', $rule->price_multiplier)
                ->first();
            if ($existing === null) {
                $candidate = $slug;
                $suffix = 1;
                while (DB::table('cleaning_dirtiness_levels')->where('slug', $candidate)->exists()) {
                    $candidate = $slug.'-'.$suffix++;
                }
                $levelId = DB::table('cleaning_dirtiness_levels')->insertGetId([
                    'name' => str_replace('_', ' ', (string) $rule->dirtiness_level),
                    'slug' => $candidate,
                    'price_multiplier' => $rule->price_multiplier,
                    'sort_order' => 0,
                    'is_active' => (bool) $rule->is_active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $levelId = (int) $existing->id;
            }

            DB::table('cleaning_special_service_dirtiness_levels')->updateOrInsert([
                'cleaning_special_service_id' => $rule->cleaning_special_service_id,
                'cleaning_dirtiness_level_id' => $levelId,
            ], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function seedEventTypes(): void
    {
        foreach (['family_dinner', 'birthday', 'large_gathering', 'funeral', 'other'] as $order => $slug) {
            DB::table('cleaning_event_types')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => str_replace('_', ' ', $slug),
                    'sort_order' => $order + 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * Link legacy event-assistance bookings to the migrated catalog while
     * retaining their original event slug in property_details. The update is
     * deliberately PHP-side so it works on both SQLite and MySQL JSON columns.
     */
    private function backfillBookingEventTypes(): void
    {
        $eventTypeIds = DB::table('cleaning_event_types')
            ->pluck('id', 'slug')
            ->mapWithKeys(static fn (mixed $id, mixed $slug): array => [
                mb_strtolower(mb_trim((string) $slug)) => (int) $id,
            ]);

        if ($eventTypeIds->isEmpty()) {
            return;
        }

        DB::table('cleaning_bookings')
            ->whereNull('cleaning_event_type_id')
            ->select(['id', 'property_details'])
            ->orderBy('id')
            ->get()
            ->each(function (object $booking) use ($eventTypeIds): void {
                $details = $booking->property_details;
                if (is_string($details)) {
                    $decoded = json_decode($details, true);
                    $details = is_array($decoded) ? $decoded : [];
                }
                if (! is_array($details)) {
                    return;
                }

                $slug = mb_strtolower(mb_trim((string) ($details['event_type'] ?? $details['eventType'] ?? '')));
                $eventTypeId = $eventTypeIds->get($slug);
                if ($eventTypeId === null) {
                    return;
                }

                DB::table('cleaning_bookings')
                    ->where('id', $booking->id)
                    ->whereNull('cleaning_event_type_id')
                    ->update(['cleaning_event_type_id' => $eventTypeId]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_schedule_change_decisions');
        Schema::dropIfExists('cleaning_schedule_change_requests');
        Schema::dropIfExists('cleaning_open_time_extensions');

        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'open_time_expected_max_minutes', 'open_time_hard_max_minutes', 'open_time_ceiling_ends_at',
                'open_time_end_requested_at', 'open_time_end_status', 'open_time_termination_reason',
                'travel_fee_mode', 'travel_fee_value',
            ]);
        });
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cleaning_event_type_id');
            $table->dropColumn([
                'event_dynamic_answers', 'open_time_expected_max_minutes', 'open_time_hard_max_minutes',
                'open_time_warning_minutes', 'open_time_extension_options', 'open_time_ceiling_ends_at',
                'open_time_end_requested_at', 'open_time_end_status', 'open_time_terminated_at',
                'open_time_terminated_by_id', 'open_time_termination_reason', 'capability_schema_version',
            ]);
        });

        Schema::dropIfExists('cleaning_event_type_special_services');
        Schema::dropIfExists('cleaning_event_type_fields');
        Schema::dropIfExists('cleaning_event_types');
        Schema::dropIfExists('cleaning_equipment_reservations');
        Schema::dropIfExists('cleaning_worker_equipment_authorizations');
        Schema::dropIfExists('cleaning_worker_special_service_skills');
        Schema::dropIfExists('cleaning_booking_special_service_sessions');
        Schema::dropIfExists('cleaning_booking_special_service_items');

        Schema::table('cleaning_booking_special_services', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cleaning_booking_session_id');
            $table->dropConstrainedForeignId('assigned_worker_id');
            $table->dropColumn(['execution_status', 'unable_reason', 'started_at', 'completed_at', 'financial_snapshot']);
        });
        Schema::table('cleaning_special_service_equipment', function (Blueprint $table): void {
            $table->dropUnique(['asset_code']);
            $table->dropColumn(['asset_code', 'status', 'buffer_before_minutes', 'buffer_after_minutes', 'last_handed_over_at', 'last_returned_at']);
        });
        Schema::dropIfExists('cleaning_special_service_dirtiness_levels');
        Schema::table('cleaning_special_services', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cleaning_special_service_category_id');
            $table->dropColumn([
                'description', 'input_type', 'unit_code', 'supports_dirtiness', 'gender_constraint',
                'estimated_duration_minutes', 'worker_pay_mode', 'worker_pay_value', 'operating_cost_mode',
                'operating_cost_value', 'travel_fee_mode', 'travel_fee_value', 'requires_before_image',
                'requires_after_image',
            ]);
        });
        Schema::dropIfExists('cleaning_dirtiness_levels');
        Schema::dropIfExists('cleaning_special_service_categories');
        Schema::dropIfExists('cleaning_booking_material_kits');
        Schema::dropIfExists('cleaning_material_type_quantity_rules');
        Schema::table('cleaning_materials', fn (Blueprint $table) => $table->dropColumn('image_path'));
    }
};
