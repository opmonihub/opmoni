<?php

$_SERVER['TEST_TOKEN'] = 'opmoni-'.getmypid();

$environment = ['APP_ENV' => 'testing', 'APP_MAINTENANCE_DRIVER' => 'file', 'CACHE_STORE' => 'array', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'SESSION_DRIVER' => 'array', 'SESSION_STORE' => '', 'SESSION_CONNECTION' => '', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
foreach ($environment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
require dirname(__DIR__).'/vendor/autoload.php';
