<?php

declare(strict_types=1);

// Two independent MySQL connections, a disposable equipment row, and a strict schema guard.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection();
$schema = 'release2_phase7_gate_20261011';
if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== $schema) {
    fwrite(STDERR, "Refusing to probe any non-gate database.\n");
    exit(2);
}
$c = config('database.connections.mysql');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $c['host'] ?? '127.0.0.1', $c['port'] ?? 3306, $schema);
$asset = null;
$a = null;
$b = null;
try {
    $asset = \Modules\Cleaning\Models\CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Release 2 disposable lock probe',
        'asset_code' => 'R2-'.bin2hex(random_bytes(7)),
        'status' => 'available',
        'is_active' => true,
    ]);
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
    $a = new PDO($dsn, (string) ($c['username'] ?? 'root'), (string) ($c['password'] ?? ''), $opts);
    $b = new PDO($dsn, (string) ($c['username'] ?? 'root'), (string) ($c['password'] ?? ''), $opts);
    $sql = 'SELECT id FROM `'.$asset->getTable().'` WHERE id = ? FOR UPDATE';
    $a->beginTransaction();
    $stmt = $a->prepare($sql);
    $stmt->execute([$asset->id]);
    if ((int) $stmt->fetchColumn() !== (int) $asset->id) {
        throw new RuntimeException('Asset lock was not acquired.');
    }
    $b->exec('SET SESSION innodb_lock_wait_timeout=1');
    $b->beginTransaction();
    $timedOut = false;
    try {
        $stmt = $b->prepare($sql);
        $stmt->execute([$asset->id]);
    } catch (PDOException $ex) {
        if ((int) ($ex->errorInfo[1] ?? 0) !== 1205) {
            throw $ex;
        }
        $timedOut = true;
    }
    if ($b->inTransaction()) {
        $b->rollBack();
    }
    if (! $timedOut) {
        throw new RuntimeException('Second connection bypassed asset row lock.');
    }
    $a->commit();
    $b->beginTransaction();
    $stmt = $b->prepare($sql);
    $stmt->execute([$asset->id]);
    if ((int) $stmt->fetchColumn() !== (int) $asset->id) {
        throw new RuntimeException('Released row was not available to peer.');
    }
    $b->commit();
    echo "PASS: second connection blocked, then acquired released asset lock.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'FAIL: '.$ex->getMessage().PHP_EOL);
    exit(1);
} finally {
    if ($a instanceof PDO && $a->inTransaction()) $a->rollBack();
    if ($b instanceof PDO && $b->inTransaction()) $b->rollBack();
    if ($asset !== null) $asset->delete();
}
