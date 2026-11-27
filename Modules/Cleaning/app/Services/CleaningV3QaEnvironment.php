<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CleaningV3QaEnvironment
{
    public const CATALOG_KEY = 'a1aad1a0-0000-4000-8000-000000000003';
    public const DATABASE = 'alnadha_worker_test';

    public function isLocalDatabase(): bool
    {
        if (! app()->environment('local', 'testing')
            || ! in_array(config('app.env'), ['local', 'testing'], true)) {
            return false;
        }

        // Inspect the resolved connection so a DB URL cannot override the target.
        $connection = DB::connection();
        $config = $connection->getConfig();
        if (($config['driver'] ?? null) !== 'mysql'
            || ($config['host'] ?? null) !== '127.0.0.1'
            || (string) ($config['port'] ?? '') !== '3306'
            || ($config['database'] ?? null) !== self::DATABASE
            || ! empty($config['unix_socket'])
            || isset($config['read']) || isset($config['write'])) {
            return false;
        }

        try {
            $server = $connection->selectOne('select database() as db, @@hostname as host, @@port as port', [], false);
        } catch (QueryException) {
            return false;
        }

        $localHost = gethostname();

        return is_string($localHost) && $localHost !== ''
            && ($server->db ?? null) === self::DATABASE
            && strcasecmp((string) ($server->host ?? ''), $localHost) === 0
            && (int) ($server->port ?? 0) === 3306;
    }

    public function signingKey(): ?string
    {
        $key = config('cleaning_v3_catalog.qa_signing_key');
        if (config('cleaning_v3_catalog.qa_enabled') !== true || ! is_string($key)
            || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || ! $this->isLocalDatabase()) {
            return null;
        }

        return hex2bin($key);
    }
}
