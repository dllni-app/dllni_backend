<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_v3_dispatch_notice_intents')) {
            throw new RuntimeException('V3 visit dispatch notice outbox must exist before cancellation metadata.');
        }
        if (Schema::hasColumn('cleaning_v3_dispatch_notice_intents', 'cancelled_at')) {
            throw new RuntimeException('Existing V3 notice cancellation schema requires explicit reconciliation.');
        }

        Schema::table('cleaning_v3_dispatch_notice_intents', static function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        // This column records whether a notification was no longer relevant.
        // Audit history must never be silently discarded on rollback.
        throw new RuntimeException('Refusing to erase V3 notification cancellation audit history.');
    }
};
