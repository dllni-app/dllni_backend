<?php

declare(strict_types=1);

use App\Enums\GenderPreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->string('gender_preference')->default(GenderPreference::Any->value)->after('preferred_worker_id');
            $table->index('gender_preference');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropIndex(['gender_preference']);
            $table->dropColumn('gender_preference');
        });
    }
};
