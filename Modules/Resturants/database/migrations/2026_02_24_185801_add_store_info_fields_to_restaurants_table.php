<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('address');
            $table->string('district')->nullable()->after('city');
            $table->text('location_details')->nullable()->after('district');
            $table->string('whatsapp_number')->nullable()->after('phone');
            $table->string('instagram_username')->nullable()->after('email');
            $table->string('facebook_page_name')->nullable()->after('instagram_username');
            $table->boolean('is_temporarily_closed')->default(false)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn([
                'city',
                'district',
                'location_details',
                'whatsapp_number',
                'instagram_username',
                'facebook_page_name',
                'is_temporarily_closed',
            ]);
        });
    }
};
