<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\User\Services\UserSupermarketCheckoutPipelineService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);

if (! $app->environment('testing') ||
    ($input['database']['database'] ?? null) !== 'dllni_promotions_test' ||
    ! in_array($input['database']['host'] ?? null, ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Promotion race workers require an isolated local test database.');
}
config([
    'database.default' => 'mysql',
    'database.connections.mysql' => $input['database'],
    'supermarket-promotions.enabled' => true,
    'broadcasting.default' => 'null',
]);
DB::purge();
if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'dllni_promotions_test') {
    throw new RuntimeException('Unexpected promotion race database.');
}
Queue::fake();
Notification::fake();
request()->headers->set('X-Supermarket-Promotions', '1');
Sanctum::actingAs(User::findOrFail($input['customer']));
touch($input['ready']);
$deadline = microtime(true) + 30;
while (! is_file($input['gate']) && microtime(true) < $deadline) {
    usleep(1000);
    clearstatcache(true, $input['gate']);
}
try {
    if (! is_file($input['gate'])) {
        throw new RuntimeException('Race gate timed out');
    }
    $order = app(UserSupermarketCheckoutPipelineService::class)->place(
        userId: $input['customer'], cartId: $input['cart'], fulfillmentType: 'pickup', receiveMode: 'immediate',
        scheduledAt: null, couponCode: null, note: null, idempotencyKey: 'promotion-race-'.$input['index'], quoteId: $input['quote'],
    );
    $result = ['status' => 201, 'id' => $order->id];
} catch (ValidationException $exception) {
    $result = ['status' => 422];
} catch (ConflictHttpException $exception) {
    $result = ['status' => 409];
} catch (Throwable $exception) {
    $result = ['status' => 500, 'error' => $exception::class.': '.$exception->getMessage()];
}
file_put_contents($input['result'], json_encode($result, JSON_THROW_ON_ERROR));
