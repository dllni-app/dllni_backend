<?php

declare(strict_types=1);

namespace Tests\Unit\Restaurant;

use App\Support\Filament\RestaurantHomePanelBootstrap;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestaurantHomePanelBootstrapTest extends TestCase
{
    public static function requestCases(): iterable
    {
        $home = '/api/v2/user/restaurants/home';
        foreach (['', '/promotions', '/food-categories', '/cuisines', '/restaurants', '/popular-meals', '/dishes'] as $section) {
            yield 'GET '.$section => ['GET', $home.$section.'?perPage=8', true];
            yield 'HEAD '.$section => ['HEAD', $home.$section, true];
        }

        yield 'unknown section' => ['GET', $home.'/unknown', false];
        yield 'nested section' => ['GET', $home.'/dishes/123', false];
        yield 'similar prefix' => ['GET', $home.'page', false];
        yield 'POST' => ['POST', $home.'/dishes', false];
        yield 'PATCH' => ['PATCH', $home, false];
        yield 'DELETE' => ['DELETE', $home, false];
        yield 'OPTIONS' => ['OPTIONS', $home, false];
        yield 'discovery' => ['GET', '/api/v2/user/restaurants/discovery', false];
        yield 'order action' => ['POST', '/api/v2/user/restaurants/orders/1/reorder', false];
        yield 'admin login' => ['GET', '/admin/login', false];
        yield 'company login' => ['GET', '/company/login', false];
        yield 'livewire' => ['POST', '/livewire/update', false];
    }

    public static function bootstrapCases(): iterable
    {
        $home = '/api/v2/user/restaurants/home/dishes';
        yield 'normal HTTP home' => [false, false, true, $home, true];
        yield 'console and long-lived workers' => [true, false, true, $home, false];
        yield 'preexisting route cache' => [false, true, true, $home, false];
        yield 'request not bound' => [false, false, false, $home, false];
        yield 'unrelated HTTP path' => [false, false, true, '/admin/login', false];
    }

    #[DataProvider('requestCases')]
    public function test_only_allowlisted_home_reads_skip_dashboard_discovery(string $method, string $path, bool $expected): void
    {
        self::assertSame($expected, RestaurantHomePanelBootstrap::isHomeRead(Request::create($path, $method)));
    }

    #[DataProvider('bootstrapCases')]
    public function test_bootstrap_guards(bool $console, bool $cached, bool $bound, string $path, bool $expected): void
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['runningInConsole', 'routesAreCached', 'bound', 'make'])
            ->getMock();

        $app->expects($this->once())->method('runningInConsole')->willReturn($console);
        $app->method('routesAreCached')->willReturn($cached);
        $app->method('bound')->willReturn($bound);
        $app->method('make')->willReturnCallback(static fn (string $abstract) => match ($abstract) {
            'request' => Request::create($path),
            default => throw new LogicException('Unexpected service '.$abstract),
        });

        self::assertSame($expected, RestaurantHomePanelBootstrap::shouldSkip($app));
    }
}
