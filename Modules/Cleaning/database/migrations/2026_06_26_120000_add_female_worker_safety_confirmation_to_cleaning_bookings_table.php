<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->string('work_environment_beneficiary_presence')->nullable()->after('gender_preference');
            $table->boolean('female_worker_safety_pledge_accepted')->default(false)->after('work_environment_beneficiary_presence');
            $table->timestamp('female_worker_safety_pledge_accepted_at')->nullable()->after('female_worker_safety_pledge_accepted');
            $table->string('female_worker_safety_pledge_version')->nullable()->after('female_worker_safety_pledge_accepted_at');
            $table->text('female_worker_safety_pledge_text')->nullable()->after('female_worker_safety_pledge_version');
            $table->index(['gender_preference', 'work_environment_beneficiary_presence'], 'cleaning_bookings_gender_work_env_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropIndex('cleaning_bookings_gender_work_env_idx');
            $table->dropColumn([
                'work_environment_beneficiary_presence',
                'female_worker_safety_pledge_accepted',
                'female_worker_safety_pledge_accepted_at',
                'female_worker_safety_pledge_version',
                'female_worker_safety_pledge_text',
            ]);
        });
    }
};
