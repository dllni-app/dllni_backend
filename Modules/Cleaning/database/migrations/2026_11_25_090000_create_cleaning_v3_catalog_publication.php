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
        Schema::create('cleaning_v3_catalog_publication', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('catalog_version_id')->nullable()->constrained('cleaning_v3_catalog_versions', 'id', 'cv3_publication_version_fk')->restrictOnDelete();
            $table->char('digest', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('cleaning_v3_catalog_publication')->insert(['id' => 1]);
    }

    public function down(): void
    {
        if (DB::table('cleaning_v3_catalog_publication')->whereNotNull('catalog_version_id')->exists()) {
            throw new RuntimeException('Cannot drop active catalog publication.');
        }
        Schema::dropIfExists('cleaning_v3_catalog_publication');
    }
};
