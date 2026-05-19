<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class AppDownloadControllerTest extends TestCase
{
    public function test_it_rejects_invalid_app_type(): void
    {
        $response = $this->getJson('/api/v1/apps/download?appType=invalid_app');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appType']);
    }

    public function test_it_returns_not_found_when_file_not_configured_or_missing(): void
    {
        config()->set('app_downloads.files.restaurant_owner_app', null);

        $response = $this->getJson('/api/v1/apps/download?appType=restaurant_owner_app');

        $response->assertStatus(404)
            ->assertJson([
                'appType' => 'restaurant_owner_app',
            ]);
    }
}

