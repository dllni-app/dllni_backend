<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cleaning_customer_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('visit_key')->default(0);
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('reporter_user_id');
            $table->string('reporter_role', 20);
            $table->json('categories');
            $table->text('description');
            $table->timestamps();
            $table->unique(['booking_id', 'visit_key'], 'cleaning_customer_incident_visit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_customer_incidents');
    }
};
