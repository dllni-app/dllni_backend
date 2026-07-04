<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;

final class AleppoNeighborhoodSeeder extends Seeder
{
    /**
     * Practical Aleppo city neighborhood seed list for cleaning dispatch.
     *
     * Notes:
     * - Keep name_ar as the canonical UI label.
     * - Keep name_en as a clean display/search name.
     * - Keep aliases broad because OpenStreetMap/Nominatim may return different transliterations
     *   depending on the mapped object, browser language, and address level.
     * - Arabic aliases include the Flutter-normalized form and the common "حي ..." prefix form
     *   so worker work-area matching stays aligned with the mobile app normalization.
     * - Do not use OSM English names as the only matching key; match by id first, then normalized
     *   Arabic/name_en/aliases for reverse-geocoding fallback.
     */
    private const array NEIGHBORHOODS = [
        ['name_ar' => 'حلب القديمة', 'name_en' => 'Old Aleppo', 'aliases' => ['Aleppo Old City', 'Ancient City of Aleppo', 'Old Aleppo district']],
        ['name_ar' => 'الجديدة', 'name_en' => 'Al-Jdayde', 'aliases' => ['Al-Jdeideh', 'Al-Judayda', 'Al-Jdeydeh', 'Jdeideh', 'Jdayde']],
        ['name_ar' => 'الجميلية', 'name_en' => 'Al-Jamiliyah', 'aliases' => ['Jamiliyah', 'Jamilia', 'Jemiliyeh', 'Al Jamiliyah']],
        ['name_ar' => 'العزيزية', 'name_en' => 'Al-Aziziyah', 'aliases' => ['Aziziyah', 'Azizieh', 'Al Aziziyah']],
        ['name_ar' => 'السليمانية', 'name_en' => 'Al-Sulaymaniyah', 'aliases' => ['Sulaymaniyah', 'Suleimaniyeh', 'Al Sulaymaniyah']],
        ['name_ar' => 'السبيل', 'name_en' => 'Al-Sabil', 'aliases' => ['Sabil', 'Sabeel', 'Al Sabil']],
        ['name_ar' => 'الموكامبو', 'name_en' => 'Al-Mokambo', 'aliases' => ['Mokambo', 'Mocambo', 'Al Mokambo']],
        ['name_ar' => 'الفرقان', 'name_en' => 'Al-Furqan', 'aliases' => ['Furqan', 'Furkan', 'Al Furqan']],
        ['name_ar' => 'الحمدانية', 'name_en' => 'Al-Hamdaniyah', 'aliases' => ['Hamdaniya', 'Hamdaniyeh', 'Al Hamdaniyah']],
        ['name_ar' => 'حلب الجديدة', 'name_en' => 'New Aleppo', 'aliases' => ['Halab al-Jadidah', 'New Aleppo district']],
        ['name_ar' => 'حلب الجديدة الشمالية', 'name_en' => 'North New Aleppo', 'aliases' => ['Northern New Aleppo', 'North New Aleppo quarter']],
        ['name_ar' => 'حلب الجديدة الجنوبية', 'name_en' => 'South New Aleppo', 'aliases' => ['Southern New Aleppo', 'South New Aleppo quarter']],
        ['name_ar' => 'المهندسين', 'name_en' => 'Al-Muhandisin', 'aliases' => ['Muhandiseen', 'Muhandisin quarter', 'Engineers quarter']],
        ['name_ar' => 'الكهرباء', 'name_en' => 'Al-Kahraba', 'aliases' => ['Kahraba', 'Al-Kahraba quarter', 'Electricity quarter']],
        ['name_ar' => 'مساكن التعليم العالي', 'name_en' => "Masaken al-Ta'leem al-Alee", 'aliases' => ['Masaken Al-Taaleem Al-Alee', 'Higher Education Housing', 'Masaken al Taleem al Alee']],
        ['name_ar' => 'البحوث العلمية', 'name_en' => "Al-Bouhouth al-Ilmiyah", 'aliases' => ['Al-Bouhouth Al-elmiyah', 'Scientific Research quarter', 'Bouhouth Ilmiyah']],
        ['name_ar' => 'مساكن التموين', 'name_en' => 'Masaken al-Tamween', 'aliases' => ['Masaken Al-Tamween', 'Tamween Housing']],
        ['name_ar' => 'الشهداء', 'name_en' => 'Al-Shuhadaa', 'aliases' => ['Shuhadaa', 'Al-Shuhadaa quarter', 'Shuhada']],
        ['name_ar' => 'توسع المدينة', 'name_en' => 'Tawaso al-Madinah', 'aliases' => ['Tawaso Al-Madinah', 'Tawassou al-Madina', 'City Expansion quarter']],
        ['name_ar' => 'البيئة', 'name_en' => "Al-Bi'ah", 'aliases' => ['Al-Beaa', 'Al-Biah', 'Environment quarter']],
        ['name_ar' => 'منيان', 'name_en' => 'Menyan', 'aliases' => ['Menyan Benyamin', 'Benyamin', 'Benyameen', 'Minyan']],
        ['name_ar' => 'الزهراء', 'name_en' => 'Al-Zahraa', 'aliases' => ['Zahraa', 'Zahra', 'Al Zahraa', 'Al-Zahraa neighbourhood']],
        ['name_ar' => 'الخالدية', 'name_en' => 'Al-Khaldiyah', 'aliases' => ['Khaldiyah', 'Khalidiya', 'Al Khaldiyah']],
        ['name_ar' => 'الأشرفية', 'name_en' => 'Al-Ashrafiyah', 'aliases' => ['Ashrafiyah', 'Ashrafieh', 'Al Ashrafiyah']],
        ['name_ar' => 'الشيخ مقصود', 'name_en' => 'Sheikh Maqsoud', 'aliases' => ['Sheikh Maksoud', 'Shaikh Maqsud', 'Sheikh Maqsoud neighborhood']],
        ['name_ar' => 'بستان القصر', 'name_en' => 'Bustan al-Qasr', 'aliases' => ['Bustan al-Qasr district', 'Bustan al Kaser', 'Bustan al Qaser']],
        ['name_ar' => 'المشهد', 'name_en' => 'Al-Mashhad', 'aliases' => ['Mashhad', 'Al Mashhad']],
        ['name_ar' => 'السكري', 'name_en' => 'Al-Sukkari', 'aliases' => ['Sukkari', 'Sukari', 'Al Sukkari']],
        ['name_ar' => 'الأنصاري', 'name_en' => 'Al-Ansari', 'aliases' => ['Ansari', 'Al Ansari']],
        ['name_ar' => 'صلاح الدين', 'name_en' => 'Salah al-Din', 'aliases' => ['Salahaddin', 'Salaheddine', 'Salah ad-Din', 'Salah el Din']],
        ['name_ar' => 'الراموسة', 'name_en' => 'Al-Ramousah', 'aliases' => ['Ramousa', 'Ramousah', 'Al Ramousah']],
        ['name_ar' => 'العامرية', 'name_en' => 'Al-Amiriyah', 'aliases' => ['Amiriyah', 'Ameriyeh', 'Al Amiriyah']],
        ['name_ar' => 'الهلك', 'name_en' => 'Al-Halak', 'aliases' => ['Halak', 'Hay al-Halak', 'Al Halak']],
        ['name_ar' => 'الشعار', 'name_en' => 'Al-Shaar', 'aliases' => ['Shaar', 'Al Shaar']],
        ['name_ar' => 'طريق الباب', 'name_en' => 'Tariq al-Bab', 'aliases' => ['Bab Road', 'Tareeq al-Bab', 'Tariq al Bab']],
        ['name_ar' => 'كرم الجبل', 'name_en' => 'Karm al-Jabal', 'aliases' => ['Karm el Jabal', 'Karm al Jabal']],
        ['name_ar' => 'كرم الطراب', 'name_en' => 'Karm al-Trab', 'aliases' => ['Karm el Trab', 'Karm al Trab']],
        ['name_ar' => 'كرم القاطرجي', 'name_en' => 'Karm al-Qaterji', 'aliases' => ['Qaterji', 'Karm al-Qaterji district', 'Karm al Qaterji']],
        ['name_ar' => 'الميسر', 'name_en' => 'Al-Maysar', 'aliases' => ['Maysar', 'Meysar', 'Al Maysar']],
        ['name_ar' => 'الصاخور', 'name_en' => 'Al-Sakhour', 'aliases' => ['Sakhour', 'Sakhoor', 'Al Sakhour']],
        ['name_ar' => 'الليرمون', 'name_en' => 'Al-Layramoun', 'aliases' => ['Layramoun', 'Lairamoun', 'Al Layramoun']],
        ['name_ar' => 'جمعية الزهراء', 'name_en' => 'Jamiyat al-Zahraa', 'aliases' => ['Zahraa Association', 'Jamiyat Al-Zahraa', 'Al Zahraa Association']],
        ['name_ar' => 'جمعية المهندسين', 'name_en' => 'Jamiyat al-Muhandisin', 'aliases' => ['Engineers Association', 'Jamiyat Al-Muhandisin', 'Al Muhandisin Association']],
        ['name_ar' => 'الأعظمية', 'name_en' => 'Al-Azamiyah', 'aliases' => ['Azamiyah', 'Azamiyeh', 'Al Azamiyah']],
        ['name_ar' => 'المرجة', 'name_en' => 'Al-Marjeh', 'aliases' => ['Marjeh', 'Marja', 'Al Marjeh']],
        ['name_ar' => 'باب النيرب', 'name_en' => 'Bab al-Nayrab', 'aliases' => ['Bab al-Nairab', 'Bab al-Nayrab district', 'Bab al Nayrab']],
        ['name_ar' => 'باب الحديد', 'name_en' => 'Bab al-Hadid', 'aliases' => ['Bab al Hadid', 'Bab Hadid']],
        ['name_ar' => 'باب الفرج', 'name_en' => 'Bab al-Faraj', 'aliases' => ['Bab al Faraj', 'Bab Faraj']],
        ['name_ar' => 'باب جنين', 'name_en' => 'Bab Jnein', 'aliases' => ['Bab Jneen', 'Bab Jenin', 'Bab Janin']],
        ['name_ar' => 'بستان الباشا', 'name_en' => 'Bustan al-Pasha', 'aliases' => ['Bustan al-Pasha district', 'Bustan Pasha', 'Bustan al Basha']],
        ['name_ar' => 'الحيدرية', 'name_en' => 'Al-Haydariyah', 'aliases' => ['Haydariyah', 'Haidariyah', 'Al Haydariyah']],
        ['name_ar' => 'بعيدين', 'name_en' => "Ba'idin", 'aliases' => ['Baidin', 'Baedeen', 'Ba idin']],
        ['name_ar' => 'مساكن هنانو', 'name_en' => 'Masaken Hanano', 'aliases' => ['Hanano Housing', 'Masaken Hannano', 'Masakin Hanano']],
        ['name_ar' => 'هنانو', 'name_en' => 'Hanano', 'aliases' => ['Hannano', 'Hanano district']],
        ['name_ar' => 'الشيخ خضر', 'name_en' => 'Sheikh Khodr', 'aliases' => ['Sheikh Khodr', 'Sheikh Khuder', 'Shaikh Khodr']],
        ['name_ar' => 'جبل بدرو', 'name_en' => 'Jabal Badro', 'aliases' => ['Jabal Badro', 'Jabal Badrro', 'Jabal Badrou']],
        ['name_ar' => 'المعادي', 'name_en' => 'Al-Maadi', 'aliases' => ['Maadi', 'Al Maadi']],
        ['name_ar' => 'الإنذارات', 'name_en' => 'Al-Indharat', 'aliases' => ['Indharat', 'Inzarat', 'Al Indharat']],
        ['name_ar' => 'النيرب', 'name_en' => 'Al-Nayrab', 'aliases' => ['Nayrab', 'Nairab', 'Al Nayrab']],
        ['name_ar' => 'السريان', 'name_en' => 'Al-Suryan', 'aliases' => ['Suryan', 'Syrian quarter', 'Al Suryan']],
    ];

    public function run(): void
    {
        foreach (self::NEIGHBORHOODS as $index => $neighborhood) {
            CleaningNeighborhood::query()->updateOrCreate(
                [
                    'normalized_name' => CleaningNeighborhoodNameNormalizer::normalize($neighborhood['name_ar']),
                ],
                [
                    'city_name' => CleaningNeighborhoodNameNormalizer::ALEPPO_CITY,
                    'name_ar' => $neighborhood['name_ar'],
                    'name_en' => $neighborhood['name_en'],
                    'aliases' => self::aliasesFor($neighborhood['name_ar'], $neighborhood['aliases']),
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @param  array<int, string>  $aliases
     * @return array<int, string>
     */
    private static function aliasesFor(string $nameAr, array $aliases): array
    {
        return array_values(array_unique(array_filter([
            ...$aliases,
            "حي {$nameAr}",
            CleaningNeighborhoodNameNormalizer::normalize($nameAr),
            CleaningNeighborhoodNameNormalizer::normalize("حي {$nameAr}"),
        ], static fn (string $alias): bool => mb_trim($alias) !== '')));
    }
}
