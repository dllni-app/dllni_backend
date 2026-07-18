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
        $assetBaseUrl = 'https://raw.githubusercontent.com/dllni-app/dllni-user-app/2b0fd06b62b470a33dc7c0d3f4431adecc627a52/assets/images';

        DB::table('cleaning_home_types')->insert([
            [
                'section' => 'property',
                'code' => 'villa',
                'title' => 'فيلا دوبلكس',
                'external_image_url' => $assetBaseUrl.'/villa_image.png',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'property',
                'code' => 'office',
                'title' => 'مكتب',
                'external_image_url' => $assetBaseUrl.'/office_image.png',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'property',
                'code' => 'apartment',
                'title' => 'شقة',
                'external_image_url' => $assetBaseUrl.'/home_image.png',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'property',
                'code' => 'studio',
                'title' => 'استديو',
                'external_image_url' => $assetBaseUrl.'/studio_image.png',
                'sort_order' => 40,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'family_dinner',
                'title' => 'عشاء عائلي',
                'external_image_url' => $assetBaseUrl.'/family_dinner.png',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'birthday',
                'title' => 'حفلة عيد ميلاد',
                'external_image_url' => $assetBaseUrl.'/party.png',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'large_gathering',
                'title' => 'عزيمة كبيرة',
                'external_image_url' => $assetBaseUrl.'/big_launch.png',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'section' => 'occasion',
                'code' => 'funeral',
                'title' => 'عزاء',
                'external_image_url' => $assetBaseUrl.'/aza.png',
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
