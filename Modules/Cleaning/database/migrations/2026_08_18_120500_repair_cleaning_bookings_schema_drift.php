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
        if (! Schema::hasTable('cleaning_bookings')) {
            return;
        }

        $addGenderPreference = ! Schema::hasColumn('cleaning_bookings', 'gender_preference');
        $addNumberOfWorkers = ! Schema::hasColumn('cleaning_bookings', 'number_of_workers');
        $addWorkEnvironment = ! Schema::hasColumn('cleaning_bookings', 'work_environment_beneficiary_presence');
        $addSafetyAccepted = ! Schema::hasColumn('cleaning_bookings', 'female_worker_safety_pledge_accepted');
        $addSafetyAcceptedAt = ! Schema::hasColumn('cleaning_bookings', 'female_worker_safety_pledge_accepted_at');
        $addSafetyVersion = ! Schema::hasColumn('cleaning_bookings', 'female_worker_safety_pledge_version');
        $addSafetyText = ! Schema::hasColumn('cleaning_bookings', 'female_worker_safety_pledge_text');
        $addCleaningServices = ! Schema::hasColumn('cleaning_bookings', 'cleaning_services');
        $addAddressLatitude = ! Schema::hasColumn('cleaning_bookings', 'address_latitude');
        $addAddressLongitude = ! Schema::hasColumn('cleaning_bookings', 'address_longitude');
        $addNeighborhoodId = ! Schema::hasColumn('cleaning_bookings', 'neighborhood_id');
        $addNeighborhoodName = ! Schema::hasColumn('cleaning_bookings', 'neighborhood_name');
        $addExtensionFeeTotal = ! Schema::hasColumn('cleaning_bookings', 'extension_fee_total');
        $addTravelDistanceKm = ! Schema::hasColumn('cleaning_bookings', 'travel_distance_km');
        $addAdminMarginAmount = ! Schema::hasColumn('cleaning_bookings', 'admin_margin_amount');
        $addIsPricingFinal = ! Schema::hasColumn('cleaning_bookings', 'is_pricing_final');
        $addWorkerCompletionMessage = ! Schema::hasColumn('cleaning_bookings', 'worker_completion_message');
        $addWorkerFinishedServices = ! Schema::hasColumn('cleaning_bookings', 'worker_finished_cleaning_services');
        $addWorkerFinishedRooms = ! Schema::hasColumn('cleaning_bookings', 'worker_finished_property_rooms');
        $addCustomerCompletionRejectionMessage = ! Schema::hasColumn('cleaning_bookings', 'customer_completion_rejection_message');
        $addCompletionRejectedAt = ! Schema::hasColumn('cleaning_bookings', 'completion_rejected_at');
        $addStartedTravelAt = ! Schema::hasColumn('cleaning_bookings', 'started_travel_at');
        $addArrivedAt = ! Schema::hasColumn('cleaning_bookings', 'arrived_at');
        $addCancellationReason = ! Schema::hasColumn('cleaning_bookings', 'cancellation_reason');
        $addCancelledByRole = ! Schema::hasColumn('cleaning_bookings', 'cancelled_by_role');

        Schema::table('cleaning_bookings', function (Blueprint $table) use (
            $addGenderPreference,
            $addNumberOfWorkers,
            $addWorkEnvironment,
            $addSafetyAccepted,
            $addSafetyAcceptedAt,
            $addSafetyVersion,
            $addSafetyText,
            $addCleaningServices,
            $addAddressLatitude,
            $addAddressLongitude,
            $addNeighborhoodId,
            $addNeighborhoodName,
            $addExtensionFeeTotal,
            $addTravelDistanceKm,
            $addAdminMarginAmount,
            $addIsPricingFinal,
            $addWorkerCompletionMessage,
            $addWorkerFinishedServices,
            $addWorkerFinishedRooms,
            $addCustomerCompletionRejectionMessage,
            $addCompletionRejectedAt,
            $addStartedTravelAt,
            $addArrivedAt,
            $addCancellationReason,
            $addCancelledByRole,
        ): void {
            if ($addGenderPreference) {
                $table->string('gender_preference')->default('any');
            }

            if ($addNumberOfWorkers) {
                $table->unsignedSmallInteger('number_of_workers')->default(1);
            }

            if ($addWorkEnvironment) {
                $table->string('work_environment_beneficiary_presence')->nullable();
            }

            if ($addSafetyAccepted) {
                $table->boolean('female_worker_safety_pledge_accepted')->default(false);
            }

            if ($addSafetyAcceptedAt) {
                $table->timestamp('female_worker_safety_pledge_accepted_at')->nullable();
            }

            if ($addSafetyVersion) {
                $table->string('female_worker_safety_pledge_version')->nullable();
            }

            if ($addSafetyText) {
                $table->text('female_worker_safety_pledge_text')->nullable();
            }

            if ($addCleaningServices) {
                $table->json('cleaning_services')->nullable();
            }

            if ($addAddressLatitude) {
                $table->decimal('address_latitude', 10, 8)->nullable();
            }

            if ($addAddressLongitude) {
                $table->decimal('address_longitude', 11, 8)->nullable();
            }

            if ($addNeighborhoodId) {
                $table->unsignedBigInteger('neighborhood_id')->nullable();
            }

            if ($addNeighborhoodName) {
                $table->string('neighborhood_name')->nullable();
            }

            if ($addExtensionFeeTotal) {
                $table->decimal('extension_fee_total', 10, 2)->default(0);
            }

            if ($addTravelDistanceKm) {
                $table->decimal('travel_distance_km', 8, 3)->nullable();
            }

            if ($addAdminMarginAmount) {
                $table->decimal('admin_margin_amount', 10, 2)->default(0);
            }

            if ($addIsPricingFinal) {
                $table->boolean('is_pricing_final')->default(true);
            }

            if ($addWorkerCompletionMessage) {
                $table->text('worker_completion_message')->nullable();
            }

            if ($addWorkerFinishedServices) {
                $table->json('worker_finished_cleaning_services')->nullable();
            }

            if ($addWorkerFinishedRooms) {
                $table->json('worker_finished_property_rooms')->nullable();
            }

            if ($addCustomerCompletionRejectionMessage) {
                $table->text('customer_completion_rejection_message')->nullable();
            }

            if ($addCompletionRejectedAt) {
                $table->timestamp('completion_rejected_at')->nullable();
            }

            if ($addStartedTravelAt) {
                $table->timestamp('started_travel_at')->nullable();
            }

            if ($addArrivedAt) {
                $table->timestamp('arrived_at')->nullable();
            }

            if ($addCancellationReason) {
                $table->string('cancellation_reason')->nullable();
            }

            if ($addCancelledByRole) {
                $table->string('cancelled_by_role', 30)->nullable();
            }
        });

        if ($addGenderPreference) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->index('gender_preference');
            });
        }

        if ($addWorkEnvironment) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->index(
                    ['gender_preference', 'work_environment_beneficiary_presence'],
                    'cleaning_bookings_gender_work_env_idx'
                );
            });
        }

        if ($addNeighborhoodId) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->index(['status', 'neighborhood_id']);
            });

            if (Schema::hasTable('cleaning_neighborhoods')) {
                Schema::table('cleaning_bookings', function (Blueprint $table): void {
                    $table->foreign('neighborhood_id')
                        ->references('id')
                        ->on('cleaning_neighborhoods')
                        ->nullOnDelete();
                });
            }
        }

        if ($addCancelledByRole) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->index('cancelled_by_role');
            });
        }

        if ($addCleaningServices && Schema::hasTable('cleaning_booking_service') && Schema::hasTable('cleaning_services')) {
            DB::table('cleaning_booking_service')
                ->join('cleaning_services', 'cleaning_services.id', '=', 'cleaning_booking_service.cleaning_service_id')
                ->select('cleaning_booking_service.cleaning_booking_id', 'cleaning_services.name', 'cleaning_booking_service.id')
                ->orderBy('cleaning_booking_service.id')
                ->get()
                ->groupBy('cleaning_booking_id')
                ->each(function ($rows, $bookingId): void {
                    $services = $rows
                        ->pluck('name')
                        ->filter(static fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                        ->map(static fn (string $name): string => trim($name))
                        ->unique()
                        ->values()
                        ->all();

                    DB::table('cleaning_bookings')
                        ->where('id', $bookingId)
                        ->update([
                            'cleaning_services' => $services !== [] ? json_encode($services, JSON_THROW_ON_ERROR) : null,
                        ]);
                });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. This migration repairs schema drift for columns
        // that belong to earlier migrations. Rolling it back must not remove valid schema
        // from databases where those earlier migrations ran correctly.
    }
};
