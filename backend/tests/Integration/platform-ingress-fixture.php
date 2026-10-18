<?php

use App\Models\Account;
use App\Models\AccountContractingCertificate;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

// This file is an explicitly enabled test server router, never a production route.
if (getenv('OPMONI_INGRESS_FIXTURE') !== '1' || ! in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    exit(2);
}
require __DIR__.'/bootstrap.php';
putenv('REDIS_HOST=redis');
$_ENV['REDIS_HOST'] = $_SERVER['REDIS_HOST'] = 'redis';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.host') !== 'postgres' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    exit(3);
}
config(['access.origin' => 'http://127.0.0.1:3316', 'queue.default' => 'sync']);
$directory = getenv('OPMONI_INGRESS_DIRECTORY');
if (PHP_SAPI === 'cli') {
    if ($argv[1] === 'init') {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $admin = User::factory()->create(['email' => 'ingress-admin@example.test', 'password' => Hash::make('fixture-only-password'), 'is_super_admin' => true]);
        $member = User::factory()->create(['email' => 'ingress-member@example.test', 'password' => Hash::make('fixture-only-password'), 'is_super_admin' => false]);
        $account = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $member->id, 'role' => 'admin']);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['CN' => 'TEST:12345678000195', 'serialNumber' => '12345678000195'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_pkcs12_export($certificate, $bytes, $key, '');
        file_put_contents($directory.'/certificate.p12', $bytes);
        chmod($directory.'/certificate.p12', 0600);
        file_put_contents($directory.'/fixture.json', json_encode(['account_id' => $account->id, 'sha256' => hash('sha256', $bytes)]));
        echo "initialized-isolated-fixtures\n";
    } elseif ($argv[1] === 'verify') {
        $bytes = file_get_contents($directory.'/certificate.p12');
        $vault = AccountContractingCertificate::sole();
        if ($vault->certificateBytes() !== $bytes || $vault->certificatePassword() !== '') {
            exit(4);
        }
        echo "stored-vault-binary-equality-and-empty-password-confirmed\n";
    } elseif ($argv[1] === 'cleanup') {
        Artisan::call('migrate:fresh', ['--force' => true]);
        foreach (['default', 'cache', 'sessions'] as $connection) {
            Redis::connection($connection)->flushdb();
        }
        echo "isolated-fixtures-cleaned\n";
    }
    exit;
}
$request = Request::capture();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$response->headers->set('X-Test-Effective-Method', $request->method());
$response->headers->set('X-Test-Trusted-Ip', (string) $request->attributes->get('access.client_ip'));
$response->headers->set('X-Test-Forwarded-For', (string) $request->header('X-Forwarded-For'));
$file = $request->file('certificate');
if ($file?->isValid()) {
    $response->headers->set('X-Test-File-Sha256', hash_file('sha256', $file->getPathname()));
}
$response->send();
$kernel->terminate($request, $response);
