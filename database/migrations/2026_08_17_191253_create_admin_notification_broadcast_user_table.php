<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_broadcast_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_notification_broadcast_id')
                ->constrained(indexName: 'notif_broadcast_user_broadcast_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(indexName: 'notif_broadcast_user_user_id_foreign')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_broadcast_user');
    }
};
