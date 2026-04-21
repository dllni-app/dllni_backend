<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use App\Models\MasterProductAlias;
use Illuminate\Database\Seeder;
use Database\Seeders\Support\SeederMedia;

final class MasterProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['id' => 1, 'name' => 'حليب كامل الدسم', 'barcode' => '6281001000011', 'unit' => MasterProductUnit::Liter, 'brand' => 'المراعي', 'description' => 'حليب بقري كامل الدسم 1 لتر', 'is_active' => true, 'aliases' => ['حليب', 'لبن حليب'], 'image_url' => 'https://images.unsplash.com/photo-1563636619-e9143da7973b?auto=format&fit=crop&w=900&q=80'],
            ['id' => 2, 'name' => 'حليب قليل الدسم', 'barcode' => '6281001000012', 'unit' => MasterProductUnit::Liter, 'brand' => 'المراعي', 'description' => 'حليب قليل الدسم 1 لتر', 'is_active' => true, 'aliases' => [], 'image_url' => 'https://images.unsplash.com/photo-1563636619-e9143da7973b?auto=format&fit=crop&w=900&q=80'],
            ['id' => 3, 'name' => 'سكر أبيض', 'barcode' => '6282002000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'الأسرة', 'description' => 'سكر أبيض ناعم 1 كغ', 'is_active' => true, 'aliases' => ['سكر'], 'image_url' => 'https://images.unsplash.com/photo-1586201375761-83865001e31c?auto=format&fit=crop&w=900&q=80'],
            ['id' => 4, 'name' => 'رز بسمتي', 'barcode' => '6283003000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'أبو كاس', 'description' => 'رز بسمتي فاخر', 'is_active' => true, 'aliases' => ['رز'], 'image_url' => 'https://images.unsplash.com/photo-1516684732162-798a0062be99?auto=format&fit=crop&w=900&q=80'],
            ['id' => 5, 'name' => 'دجاج طازج', 'barcode' => '6284004000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'الوطنية', 'description' => 'دجاج طازج كامل', 'is_active' => true, 'aliases' => ['فروج', 'دجاجة'], 'image_url' => 'https://images.unsplash.com/photo-1604503468506-a8da13d82791?auto=format&fit=crop&w=900&q=80'],
            ['id' => 6, 'name' => 'طماطم', 'barcode' => '0000000001001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'محلي', 'description' => 'طماطم طازجة', 'is_active' => true, 'aliases' => ['بندورة'], 'image_url' => 'https://images.unsplash.com/photo-1561136594-7f68413baa99?auto=format&fit=crop&w=900&q=80'],
            ['id' => 7, 'name' => 'خيار', 'barcode' => '0000000001002', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'محلي', 'description' => 'خيار طازج', 'is_active' => true, 'aliases' => [], 'image_url' => 'https://images.unsplash.com/photo-1449300079323-02e209d9d3a6?auto=format&fit=crop&w=900&q=80'],
            ['id' => 8, 'name' => 'جبنة موزاريلا', 'barcode' => '6285005000001', 'unit' => MasterProductUnit::Gram, 'brand' => 'برايد', 'description' => 'جبنة موزاريلا مبشورة', 'is_active' => true, 'aliases' => ['موزريلا'], 'image_url' => 'https://images.unsplash.com/photo-1486297678162-eb2a19b0a32d?auto=format&fit=crop&w=900&q=80'],
            ['id' => 9, 'name' => 'زيت زيتون', 'barcode' => '6286006000001', 'unit' => MasterProductUnit::Liter, 'brand' => 'الجوف', 'description' => 'زيت زيتون بكر ممتاز', 'is_active' => true, 'aliases' => ['زيت'], 'image_url' => 'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?auto=format&fit=crop&w=900&q=80'],
            ['id' => 10, 'name' => 'معكرونة سباغيتي', 'barcode' => '6287007000001', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'العلالي', 'description' => 'معكرونة سباغيتي 500غ', 'is_active' => true, 'aliases' => ['باستا'], 'image_url' => 'https://images.unsplash.com/photo-1551462147-82aa57f57d2f?auto=format&fit=crop&w=900&q=80'],
            ['id' => 11, 'name' => 'خبز عربي أبيض', 'barcode' => '6288008000001', 'unit' => MasterProductUnit::Piece, 'brand' => 'الفرن البلدي', 'description' => 'خبز عربي طازج يومي', 'is_active' => true, 'aliases' => ['خبز', 'خبز عربي'], 'image_url' => 'https://images.unsplash.com/photo-1549931319-a545dcf3bc73?auto=format&fit=crop&w=900&q=80'],
            ['id' => 12, 'name' => 'خبز قمح كامل', 'barcode' => '6288008000002', 'unit' => MasterProductUnit::Piece, 'brand' => 'الفرن البلدي', 'description' => 'خبز قمح كامل غني بالألياف', 'is_active' => true, 'aliases' => ['خبز أسمر'], 'image_url' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=900&q=80'],
            ['id' => 13, 'name' => 'خبز توست أبيض', 'barcode' => '6288008000003', 'unit' => MasterProductUnit::Pack, 'brand' => 'الفرن البلدي', 'description' => 'خبز توست طري للتقديم اليومي', 'is_active' => true, 'aliases' => ['توست', 'خبز ساندويش'], 'image_url' => 'https://images.unsplash.com/photo-1483691278019-5a4d3d2fd0f4?auto=format&fit=crop&w=900&q=80'],
            ['id' => 14, 'name' => 'خبز توست أسمر', 'barcode' => '6288008000004', 'unit' => MasterProductUnit::Pack, 'brand' => 'الفرن البلدي', 'description' => 'خبز توست بالحبوب الكاملة', 'is_active' => true, 'aliases' => ['توست أسمر'], 'image_url' => 'https://images.unsplash.com/photo-1586444248902-2f64eddc13df?auto=format&fit=crop&w=900&q=80'],
            ['id' => 15, 'name' => 'خبز برجر', 'barcode' => '6288008000005', 'unit' => MasterProductUnit::Pack, 'brand' => 'الفرن البلدي', 'description' => 'خبز برجر طري للتقديم', 'is_active' => true, 'aliases' => ['برجر'], 'image_url' => 'https://images.unsplash.com/photo-1590080877777-1d4f6f0c8c3d?auto=format&fit=crop&w=900&q=80'],
            ['id' => 16, 'name' => 'خبز صمون', 'barcode' => '6288008000006', 'unit' => MasterProductUnit::Pack, 'brand' => 'الفرن البلدي', 'description' => 'خبز صمون ساندويشات', 'is_active' => true, 'aliases' => ['صمون'], 'image_url' => 'https://images.unsplash.com/photo-1608198093002-ad4e005484ec?auto=format&fit=crop&w=900&q=80'],
            ['id' => 17, 'name' => 'لبنة بلدية', 'barcode' => '6289009000001', 'unit' => MasterProductUnit::Gram, 'brand' => 'الصفدي', 'description' => 'لبنة بلدية 400غ', 'is_active' => true, 'aliases' => ['لبنة'], 'image_url' => 'https://images.unsplash.com/photo-1486297678162-eb2a19b0a32d?auto=format&fit=crop&w=900&q=80'],
            ['id' => 18, 'name' => 'حلاوة طحينية', 'barcode' => '6289009000002', 'unit' => MasterProductUnit::Gram, 'brand' => 'الصفدي', 'description' => 'حلاوة طحينية بالفستق', 'is_active' => true, 'aliases' => ['حلاوة'], 'image_url' => 'https://images.unsplash.com/photo-1551024601-bec78aea704b?auto=format&fit=crop&w=900&q=80'],
            ['id' => 19, 'name' => 'زيتون أخضر', 'barcode' => '6289009000003', 'unit' => MasterProductUnit::Gram, 'brand' => 'الجوف', 'description' => 'زيتون أخضر منزوع النوى', 'is_active' => true, 'aliases' => ['زيتون'], 'image_url' => 'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?auto=format&fit=crop&w=900&q=80'],
            ['id' => 20, 'name' => 'مربى فراولة', 'barcode' => '6289009000004', 'unit' => MasterProductUnit::Gram, 'brand' => 'الصفدي', 'description' => 'مربى فراولة 450غ', 'is_active' => true, 'aliases' => ['مربى'], 'image_url' => 'https://images.unsplash.com/photo-1532634896-26909d0d1f4c?auto=format&fit=crop&w=900&q=80'],
            ['id' => 21, 'name' => 'شاي أحمر', 'barcode' => '6289009000005', 'unit' => MasterProductUnit::Pack, 'brand' => 'الصفدي', 'description' => 'شاي أحمر سائب', 'is_active' => true, 'aliases' => ['شاي'], 'image_url' => 'https://images.unsplash.com/photo-1544787219-7f47ccb76574?auto=format&fit=crop&w=900&q=80'],
            ['id' => 22, 'name' => 'زبدة طبيعية', 'barcode' => '6289009000006', 'unit' => MasterProductUnit::Gram, 'brand' => 'المراعي', 'description' => 'زبدة طبيعية 200غ', 'is_active' => true, 'aliases' => ['زبدة'], 'image_url' => 'https://images.unsplash.com/photo-1589985270826-4b7bb135bc9d?auto=format&fit=crop&w=900&q=80'],
        ];

        foreach ($products as $data) {
            $aliases = $data['aliases'];
            $imageUrl = $data['image_url'];
            unset($data['aliases']);
            unset($data['image_url']);

            $product = MasterProduct::updateOrCreate(
                ['id' => $data['id']],
                [
                    'name' => $data['name'],
                    'unit' => $data['unit']->value,
                    'brand' => $data['brand'],
                    'description' => $data['description'],
                    'is_active' => $data['is_active'],
                ]
            );

            SeederMedia::ensureSingleMedia(
                $product,
                MasterProduct::IMAGE_COLLECTION,
                $imageUrl,
                'master-product-' . $product->id
            );

            foreach ($aliases as $alias) {
                MasterProductAlias::firstOrCreate(
                    ['master_product_id' => $product->id, 'alias' => $alias]
                );
            }
        }
    }
}
