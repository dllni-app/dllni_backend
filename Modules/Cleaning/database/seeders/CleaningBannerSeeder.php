<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Cleaning\Models\CleaningBanner;

final class CleaningBannerSeeder extends Seeder
{
    private const IMAGE_PATH = 'cleaning-banners/cleaning-home-banner.svg';

    public function run(): void
    {
        Storage::disk('public')->put(self::IMAGE_PATH, $this->bannerSvg());

        CleaningBanner::updateOrCreate(
            ['title' => 'تنظيف منزلك بسهولة'],
            [
                'subtitle' => 'احجزي فريق تنظيف موثوق لبيتك أو مكتبك في الوقت المناسب لك.',
                'image_path' => self::IMAGE_PATH,
                'target_url' => 'dllni://cleaning/book',
                'sort_order' => 0,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
                'is_active' => true,
            ],
        );
    }

    private function bannerSvg(): string
    {
        return <<<'SVG'
<svg width="1200" height="520" viewBox="0 0 1200 520" fill="none" xmlns="http://www.w3.org/2000/svg">
  <rect width="1200" height="520" rx="44" fill="#EAFBF7"/>
  <rect x="64" y="64" width="1072" height="392" rx="36" fill="url(#background)"/>
  <circle cx="1014" cy="112" r="102" fill="#FFFFFF" fill-opacity="0.38"/>
  <circle cx="1014" cy="112" r="56" fill="#A7F3D0" fill-opacity="0.7"/>
  <circle cx="166" cy="428" r="70" fill="#FFFFFF" fill-opacity="0.32"/>
  <path d="M129 142C129 125.431 142.431 112 159 112H590C606.569 112 620 125.431 620 142V382C620 398.569 606.569 412 590 412H159C142.431 412 129 398.569 129 382V142Z" fill="#FFFFFF" fill-opacity="0.9"/>
  <path d="M184 188H390" stroke="#13BFA6" stroke-width="22" stroke-linecap="round"/>
  <path d="M184 242H500" stroke="#94A3B8" stroke-width="18" stroke-linecap="round"/>
  <path d="M184 292H454" stroke="#CBD5E1" stroke-width="18" stroke-linecap="round"/>
  <rect x="184" y="334" width="188" height="46" rx="23" fill="#13BFA6"/>
  <path d="M740 173C740 150.356 758.356 132 781 132H955C977.644 132 996 150.356 996 173V365C996 387.644 977.644 406 955 406H781C758.356 406 740 387.644 740 365V173Z" fill="#FFFFFF"/>
  <path d="M785 190C785 179.507 793.507 171 804 171H932C942.493 171 951 179.507 951 190V237C951 247.493 942.493 256 932 256H804C793.507 256 785 247.493 785 237V190Z" fill="#ECFEFF"/>
  <path d="M817 226C851 193 886 193 920 226" stroke="#13BFA6" stroke-width="14" stroke-linecap="round"/>
  <path d="M825 313L863 351L927 287" stroke="#13BFA6" stroke-width="22" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M686 366C686 342.804 704.804 324 728 324H756V406H728C704.804 406 686 389.196 686 366Z" fill="#0F766E"/>
  <path d="M704 228L754 324H726L676 228H704Z" fill="#0F766E"/>
  <path d="M662 202C662 186.536 674.536 174 690 174H710C725.464 174 738 186.536 738 202V234H662V202Z" fill="#14B8A6"/>
  <path d="M642 234H758V260C758 274.359 746.359 286 732 286H668C653.641 286 642 274.359 642 260V234Z" fill="#5EEAD4"/>
  <path d="M1015 302C1015 284.327 1029.33 270 1047 270C1064.67 270 1079 284.327 1079 302C1079 332 1047 362 1047 362C1047 362 1015 332 1015 302Z" fill="#FFFFFF" fill-opacity="0.82"/>
  <circle cx="1047" cy="302" r="13" fill="#13BFA6"/>
  <defs>
    <linearGradient id="background" x1="64" y1="64" x2="1113.95" y2="503.306" gradientUnits="userSpaceOnUse">
      <stop stop-color="#14B8A6"/>
      <stop offset="1" stop-color="#0F766E"/>
    </linearGradient>
  </defs>
</svg>
SVG;
    }
}
