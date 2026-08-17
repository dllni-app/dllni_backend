<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('audience_type');
            $table->json('module_types')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_broadcasts');
    }
};
