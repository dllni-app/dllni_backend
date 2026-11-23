<?php

declare(strict_types=1);

it('publishes a detailed Arabic compliance privacy notice without requiring login', function (): void {
    $this->get(route('legal.cleaning-worker-monitoring'))
        ->assertOk()
        ->assertSee('مراقبة التزام')
        ->assertSee('المشاركة اختيارية')
        ->assertSee('7 أيام')
        ->assertSee('180 يوماً')
        ->assertSee('إيقاف المشاركة');
});
