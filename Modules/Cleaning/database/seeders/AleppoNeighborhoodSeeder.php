<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;

final class AleppoNeighborhoodSeeder extends Seeder
{
    private const array NEIGHBORHOODS = [
        ['name_ar' => 'حلب القديمة', 'name_en' => 'Old Aleppo', 'aliases' => ['Aleppo Old City', 'Old Aleppo district']],
        ['name_ar' => 'الجميلية', 'name_en' => 'Al-Jamiliyah', 'aliases' => ['Jamiliyah', 'Jamilia']],
        ['name_ar' => 'العزيزية', 'name_en' => 'Al-Aziziyah', 'aliases' => ['Aziziyah', 'Azizieh']],
        ['name_ar' => 'السليمانية', 'name_en' => 'Al-Sulaymaniyah', 'aliases' => ['Sulaymaniyah', 'Suleimaniyeh']],
        ['name_ar' => 'السبيل', 'name_en' => 'Al-Sabil', 'aliases' => ['Sabil', 'Sabeel']],
        ['name_ar' => 'الموكامبو', 'name_en' => 'Al-Mokambo', 'aliases' => ['Mokambo']],
        ['name_ar' => 'الفرقان', 'name_en' => 'Al-Furqan', 'aliases' => ['Furqan', 'Furkan']],
        ['name_ar' => 'الحمدانية', 'name_en' => 'Al-Hamdaniyah', 'aliases' => ['Hamdaniya', 'Hamdaniyeh']],
        ['name_ar' => 'حلب الجديدة', 'name_en' => 'New Aleppo', 'aliases' => ['New Aleppo district']],
        ['name_ar' => 'الزهراء', 'name_en' => 'Al-Zahraa', 'aliases' => ['Zahraa', 'Zahra']],
        ['name_ar' => 'الخالدية', 'name_en' => 'Al-Khaldiyah', 'aliases' => ['Khaldiyah', 'Khalidiya']],
        ['name_ar' => 'الأشرفية', 'name_en' => 'Al-Ashrafiyah', 'aliases' => ['Ashrafiyah', 'Ashrafieh']],
        ['name_ar' => 'الشيخ مقصود', 'name_en' => 'Sheikh Maqsoud', 'aliases' => ['Sheikh Maksoud', 'Shaikh Maqsud']],
        ['name_ar' => 'بستان القصر', 'name_en' => 'Bustan al-Qasr', 'aliases' => ['Bustan al-Qasr district', 'Bustan al Kaser']],
        ['name_ar' => 'المشهد', 'name_en' => 'Al-Mashhad', 'aliases' => ['Mashhad']],
        ['name_ar' => 'السكري', 'name_en' => 'Al-Sukkari', 'aliases' => ['Sukkari', 'Sukari']],
        ['name_ar' => 'الأنصاري', 'name_en' => 'Al-Ansari', 'aliases' => ['Ansari']],
        ['name_ar' => 'صلاح الدين', 'name_en' => 'Salah al-Din', 'aliases' => ['Salahaddin', 'Salaheddine']],
        ['name_ar' => 'الراموسة', 'name_en' => 'Al-Ramousah', 'aliases' => ['Ramousa', 'Ramousah']],
        ['name_ar' => 'العامرية', 'name_en' => 'Al-Amiriyah', 'aliases' => ['Amiriyah', 'Ameriyeh']],
        ['name_ar' => 'الهلك', 'name_en' => 'Al-Halak', 'aliases' => ['Halak', 'Hay al-Halak']],
        ['name_ar' => 'الشعار', 'name_en' => 'Al-Shaar', 'aliases' => ['Shaar']],
        ['name_ar' => 'طريق الباب', 'name_en' => 'Tariq al-Bab', 'aliases' => ['Bab Road', 'Tareeq al-Bab']],
        ['name_ar' => 'كرم الجبل', 'name_en' => 'Karm al-Jabal', 'aliases' => ['Karm el Jabal']],
        ['name_ar' => 'كرم الطراب', 'name_en' => 'Karm al-Trab', 'aliases' => ['Karm el Trab']],
        ['name_ar' => 'كرم القاطرجي', 'name_en' => 'Karm al-Qaterji', 'aliases' => ['Qaterji', 'Karm al-Qaterji district']],
        ['name_ar' => 'الميسر', 'name_en' => 'Al-Maysar', 'aliases' => ['Maysar', 'Meysar']],
        ['name_ar' => 'الصاخور', 'name_en' => 'Al-Sakhour', 'aliases' => ['Sakhour', 'Sakhoor']],
        ['name_ar' => 'الليرمون', 'name_en' => 'Al-Layramoun', 'aliases' => ['Layramoun', 'Lairamoun']],
        ['name_ar' => 'جمعية الزهراء', 'name_en' => 'Jamiyat al-Zahraa', 'aliases' => ['Zahraa Association']],
        ['name_ar' => 'جمعية المهندسين', 'name_en' => 'Jamiyat al-Muhandisin', 'aliases' => ['Engineers Association']],
        ['name_ar' => 'الأعظمية', 'name_en' => 'Al-Azamiyah', 'aliases' => ['Azamiyah', 'Azamiyah']],
        ['name_ar' => 'المرجة', 'name_en' => 'Al-Marjeh', 'aliases' => ['Marjeh', 'Marja']],
        ['name_ar' => 'باب النيرب', 'name_en' => 'Bab al-Nayrab', 'aliases' => ['Bab al-Nairab', 'Bab al-Nayrab district']],
        ['name_ar' => 'باب الحديد', 'name_en' => 'Bab al-Hadid', 'aliases' => ['Bab al Hadid']],
        ['name_ar' => 'باب الفرج', 'name_en' => 'Bab al-Faraj', 'aliases' => ['Bab al Faraj']],
        ['name_ar' => 'باب جنين', 'name_en' => 'Bab Jneen', 'aliases' => ['Bab Jenin', 'Bab Janin']],
        ['name_ar' => 'بستان الباشا', 'name_en' => 'Bustan al-Pasha', 'aliases' => ['Bustan al-Pasha district', 'Bustan Pasha', 'Bustan al Basha']],
        ['name_ar' => 'الفراتي', 'name_en' => 'Al-Furati', 'aliases' => ['Furati']],
        ['name_ar' => 'السرايا الجديدة', 'name_en' => 'Al-Sarayya al-Jadida', 'aliases' => ['New Saraya', 'Sarayya Jadida']],
    ];

    public function run(): void
    {
        foreach (self::NEIGHBORHOODS as $index => $neighborhood) {
            CleaningNeighborhood::query()->updateOrCreate(
                [
                    'normalized_name' => CleaningNeighborhoodNameNormalizer::normalize($neighborhood['name_ar']),
                ],
                [
                    'city_name' => 'حلب',
                    'name_ar' => $neighborhood['name_ar'],
                    'name_en' => $neighborhood['name_en'],
                    'aliases' => $neighborhood['aliases'],
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
