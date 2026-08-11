<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class LandingPageLinksTest extends TestCase
{
    public function test_landing_page_uses_current_download_and_whatsapp_links(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('https://play.google.com/store/apps/details?id=com.alnadha.app&pcampaignid=web_share', false)
            ->assertSee('https://alnadha.net/v1/apps/download?appType=user_app', false)
            ->assertSee('https://wa.me/963948388930', false)
            ->assertSee('تحميل مباشر', false)
            ->assertSee('تواصل مع الإدارة عبر واتساب', false)
            ->assertDontSee('href="mailto:', false);
    }
}
