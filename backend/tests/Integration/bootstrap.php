<?php

$_SERVER['TEST_TOKEN'] = 'opmoni-'.getmypid();

$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'opmoni_test',
    'DB_HOST' => 'postgres',
    'DB_USERNAME' => 'opmoni',
    'DB_PASSWORD' => 'opmoni',
    'DB_URL' => '',
    'CACHE_STORE' => 'redis',
    'CACHE_PREFIX' => 'opmoni-access-test-cache-',
    'SESSION_DRIVER' => 'redis',
    'SESSION_CONNECTION' => 'sessions',
    'SESSION_STORE' => 'sessions',
    'REDIS_DB' => '13',
    'REDIS_CACHE_DB' => '14',
    'REDIS_SESSION_DB' => '15',
    'REDIS_PREFIX' => 'opmoni-access-test-',
    'FRONTEND_INGRESS_KEY' => 'integration-test-only-key',
    'FRONTEND_ORIGIN' => 'http://localhost:3000',
    'HASH_DRIVER' => 'argon2id',
    'ARGON_MEMORY' => '65536',
    'ARGON_TIME' => '2',
    'ARGON_THREADS' => '1',
];
foreach ($testEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
