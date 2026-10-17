<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_materials', function (Blueprint $table): void {
            foreach (['used_quantity', 'returned_quantity', 'damaged_quantity', 'lost_quantity'] as $column) {
                if (! Schema::hasColumn('cleaning_booking_materials', $column)) {
                    $table->decimal($column, 12, 3)->nullable();
                }
            }
            if (! Schema::hasColumn('cleaning_booking_materials', 'reconciled_at')) {
                $table->timestamp('reconciled_at')->nullable();
            }
            if (! Schema::hasColumn('cleaning_booking_materials', 'reconciled_by_user_id')) {
                $table->foreignId('reconciled_by_user_id')->nullable();
            }
        });
        $this->ensureForeignKey('cleaning_booking_materials', 'reconciled_by_user_id', 'users', 'c_mat_rec_user_fk');

        Schema::table('cleaning_booking_material_kits', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_booking_material_kits', 'received_by_supervisor_user_id')) {
                $table->foreignId('received_by_supervisor_user_id')->nullable();
            }
        });
        $this->ensureForeignKey('cleaning_booking_material_kits', 'received_by_supervisor_user_id', 'users', 'c_mat_kit_sup_fk');

        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_equipment_reservations', 'cleaning_booking_id')) {
                $table->foreignId('cleaning_booking_id')->nullable();
            }
            if (! Schema::hasColumn('cleaning_equipment_reservations', 'supervisor_user_id')) {
                $table->foreignId('supervisor_user_id')->nullable();
            }
        });
        $this->ensureForeignKey('cleaning_equipment_reservations', 'cleaning_booking_id', 'cleaning_bookings', 'c_equip_booking_fk');
        $this->ensureForeignKey('cleaning_equipment_reservations', 'supervisor_user_id', 'users', 'c_equip_supervisor_fk');
        if (! Schema::hasIndex('cleaning_equipment_reservations', 'clean_equip_booking_status_idx')) {
            Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
                $table->index(['cleaning_booking_id', 'status'], 'clean_equip_booking_status_idx');
            });
        }
        $serviceColumn = collect(Schema::getColumns('cleaning_equipment_reservations'))
            ->firstWhere('name', 'cleaning_booking_special_service_id');
        if (! ($serviceColumn['nullable'] ?? false)) {
            Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
                $table->foreignId('cleaning_booking_special_service_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Illuminate\Support\Facades\DB::table('cleaning_equipment_reservations')->whereNull('cleaning_booking_special_service_id')->exists()) {
            throw new RuntimeException('Cannot roll back while booking-level equipment checkouts exist.');
        }
        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_special_service_id')->nullable(false)->change();
        });
        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->dropIndex('clean_equip_booking_status_idx');
        });
        $this->dropForeignKey('cleaning_equipment_reservations', 'cleaning_booking_id');
        $this->dropForeignKey('cleaning_equipment_reservations', 'supervisor_user_id');
        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->dropColumn(['cleaning_booking_id', 'supervisor_user_id']);
        });
        $this->dropForeignKey('cleaning_booking_material_kits', 'received_by_supervisor_user_id');
        Schema::table('cleaning_booking_material_kits', function (Blueprint $table): void {
            $table->dropColumn('received_by_supervisor_user_id');
        });
        $this->dropForeignKey('cleaning_booking_materials', 'reconciled_by_user_id');
        Schema::table('cleaning_booking_materials', function (Blueprint $table): void {
            $table->dropColumn(['used_quantity', 'returned_quantity', 'damaged_quantity', 'lost_quantity', 'reconciled_at', 'reconciled_by_user_id']);
        });
    }

    private function ensureForeignKey(string $tableName, string $column, string $references, string $constraint): void
    {
        if ($this->foreignKeyName($tableName, $column) !== null) {
            return;
        }
        Schema::table($tableName, function (Blueprint $table) use ($column, $references, $constraint): void {
            $table->foreign($column, $constraint)->references('id')->on($references)->nullOnDelete();
        });
    }

    private function dropForeignKey(string $tableName, string $column): void
    {
        $name = $this->foreignKeyName($tableName, $column);
        if ($name !== null) {
            Schema::table($tableName, function (Blueprint $table) use ($name): void {
                $table->dropForeign($name);
            });
        }
    }

    private function foreignKeyName(string $tableName, string $column): ?string
    {
        foreach (Schema::getForeignKeys($tableName) as $foreign) {
            if (in_array($column, $foreign['columns'] ?? [], true)) {
                return $foreign['name'];
            }
        }

        return null;
    }
};
