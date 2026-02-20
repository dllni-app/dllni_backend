<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use App\Models\MasterProductAlias;
use Illuminate\Database\Seeder;

final class MasterProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'طماطم',
                'barcode' => '5901234123457',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'مزرعة طازجة',
                'description' => 'طماطم عنقودية طازجة',
                'aliases' => ['طماطم كرزية', 'طماطم روما'],
            ],
            [
                'name' => 'زيت زيتون',
                'barcode' => '5901234123458',
                'unit' => MasterProductUnit::Liter,
                'brand' => 'ذهب المتوسط',
                'description' => 'زيت زيتون بكر ممتاز',
                'aliases' => ['زيت بكر', 'زيت زيتون بكر ممتاز'],
            ],
            [
                'name' => 'صدر دجاج',
                'barcode' => '5901234123459',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'مزرعة طازجة',
                'description' => 'صدر دجاج بدون جلد',
                'aliases' => ['دجاج', 'لحم أبيض'],
            ],
            [
                'name' => 'أرز',
                'barcode' => '5901234123460',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'حبة الذهب',
                'description' => 'أرز بسمتي حبة طويلة',
                'aliases' => ['بسمتي', 'أرز أبيض'],
            ],
            [
                'name' => 'دقيق متعدد الاستخدام',
                'barcode' => '5901234123461',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => 'اختيار الخباز',
                'description' => 'دقيق قمح للخبز',
                'aliases' => ['دقيق', 'دقيق عادي'],
            ],
            [
                'name' => 'حليب',
                'barcode' => '5901234123462',
                'unit' => MasterProductUnit::Liter,
                'brand' => 'ألبان طازجة',
                'description' => 'حليب كامل الدسم',
                'aliases' => ['حليب كامل', 'حليب طازج'],
            ],
            [
                'name' => 'بيض',
                'barcode' => '5901234123463',
                'unit' => MasterProductUnit::Pack,
                'brand' => 'بيض المزرعة',
                'description' => 'بيض حر المدى، علبة 12',
                'aliases' => ['بيض', 'دزينة بيض'],
            ],
            [
                'name' => 'بصل',
                'barcode' => '5901234123464',
                'unit' => MasterProductUnit::Kilogram,
                'brand' => null,
                'description' => 'بصل أصفر',
                'aliases' => ['بصل أصفر', 'بصل للطبخ'],
            ],
            [
                'name' => 'ثوم',
                'barcode' => '5901234123465',
                'unit' => MasterProductUnit::Piece,
                'brand' => null,
                'description' => 'رأس ثوم طازج',
                'aliases' => ['رأس ثوم', 'ثوم طازج'],
            ],
            [
                'name' => 'ليمون',
                'barcode' => '5901234123466',
                'unit' => MasterProductUnit::Piece,
                'brand' => null,
                'description' => 'ليمون طازج',
                'aliases' => ['ليمون', 'حمضيات'],
            ],
        ];

        foreach ($products as $data) {
            $aliases = $data['aliases'] ?? [];
            unset($data['aliases']);

            $product = MasterProduct::firstOrCreate(
                ['barcode' => $data['barcode']],
                [
                    'name' => $data['name'],
                    'unit' => $data['unit']->value,
                    'brand' => $data['brand'],
                    'description' => $data['description'],
                    'is_active' => true,
                ]
            );

            foreach ($aliases as $alias) {
                MasterProductAlias::firstOrCreate(
                    [
                        'master_product_id' => $product->id,
                        'alias' => $alias,
                    ]
                );
            }
        }
    }
}
