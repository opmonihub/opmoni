<?php

namespace App\Services;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ExternalEffectJournalConnection
{
    private ?Connection $connection = null;

    /** @param Closure(): Connection|null $factory Explicit isolated fixture, allowed only in tests. */
    public function __construct(private ?Closure $factory = null) {}

    public function connection(): Connection
    {
        try {
            $primary = DB::connection();
            if ($this->connection === null) {
                if ($this->factory !== null) {
                    if (! app()->runningUnitTests()) {
                        throw new RuntimeException;
                    }
                    $this->connection = ($this->factory)();
                } else {
                    $config = $primary->getConfig();
                    unset($config['read'], $config['write'], $config['direct'], $config['url'], $config['sticky']);
                    if (($config['driver'] ?? null) === 'sqlite' && ($config['database'] === ':memory:' || str_contains($config['database'], 'mode=memory'))) {
                        throw new RuntimeException;
                    }
                    $config['name'] = 'external-effect-journal-'.Str::uuid();
                    $this->connection = DB::build($config);
                }
                $this->connection->disableQueryLog();
                $this->connection->flushQueryLog();
                $this->connection->unsetEventDispatcher();
                if ($this->connection->getDriverName() === 'pgsql') {
                    $this->connection->statement("SET statement_timeout = '1500ms'");
                    $this->connection->statement("SET lock_timeout = '500ms'");
                } elseif ($this->connection->getDriverName() === 'sqlite') {
                    $this->connection->statement('PRAGMA busy_timeout = 100');
                }
            }
            if ($this->connection === $primary || $this->connection->getPdo() === $primary->getPdo()
                || $this->connection->transactionLevel() !== 0 || $this->connection->getPdo()->inTransaction()
                || ($this->factory === null && $primary->getDriverName() === 'sqlite'
                    && ($primary->transactionLevel() !== 0 || $primary->getPdo()->inTransaction()))) {
                throw new RuntimeException;
            }

            return $this->connection;
        } catch (Throwable) {
            throw new RuntimeException('O journal externo exige conexão independente em autocommit.');
        }
    }
}
