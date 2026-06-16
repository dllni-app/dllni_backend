<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('smsable');
            $table->string('provider')->default('mtn');
            $table->string('gsm');
            $table->text('message');
            $table->unsignedTinyInteger('lang');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('provider_status_code')->nullable();
            $table->text('provider_response')->nullable();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
