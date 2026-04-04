<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // This migration enables media library support for categories
        // The media table will automatically store category images
        // No schema changes needed - media library uses media table
    }

    public function down(): void
    {
        // Media library handles cleanup
    }
};
