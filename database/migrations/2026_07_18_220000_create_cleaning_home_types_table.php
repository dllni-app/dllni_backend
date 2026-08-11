<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('cleaning_home_types', function (Blueprint $table): void {
            $table->id();
            $table->string('section', 32);
            $table->string('code', 100);
            $table->string('booking_value', 100);
            $table->string('title');
            $table->string('image_path')->nullable();
            $table->text('external_image_url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['section', 'code']);
            $table->index(['section', 'is_active', 'sort_order']);
        });

        $now = now();
        DB::table('cleaning_home_types')->insert([
            [
                'section' => 'property',
                'code' => 'villa',
                'booking_value' => 'villa',
                'title' => 'فيلا دوبلكس',
                'image_path' => 'cleaning-home-types/villa_image.png',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'property',
                'code' => 'office',
                'booking_value' => 'office',
                'title' => 'مكتب',
                'image_path' => 'cleaning-home-types/cleaning_banner.png',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'property',
                'code' => 'apartment',
                'booking_value' => 'apartment',
                'title' => 'شقة',
                'image_path' => 'cleaning-home-types/home_image.png',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'property',
                'code' => 'studio',
                'booking_value' => 'studio',
                'title' => 'استديو',
                'image_path' => 'cleaning-home-types/studio_image.png',
                'sort_order' => 40,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'family_dinner',
                'booking_value' => 'family_dinner',
                'title' => 'عشاء عائلي',
                'image_path' => 'cleaning-home-types/family_dinner.png',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'birthday',
                'booking_value' => 'birthday',
                'title' => 'حفلة عيد ميلاد',
                'image_path' => 'cleaning-home-types/party.png',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'large_gathering',
                'booking_value' => 'large_gathering',
                'title' => 'عزيمة كبيرة',
                'image_path' => 'cleaning-home-types/big_launch.png',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'funeral',
                'booking_value' => 'funeral',
                'title' => 'عزاء',
                'image_path' => 'cleaning-home-types/aza.png',
                'sort_order' => 40,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_home_types');
    }
};
