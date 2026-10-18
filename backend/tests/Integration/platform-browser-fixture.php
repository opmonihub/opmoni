<?php

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\SerproConnection;
use App\Models\User;
use App\Services\AccountCertificateVault;
use App\Services\SerproConnectionManager;
use App\Services\SerproCredentialLifecycle;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/** Explicitly enabled isolated browser fixture; never registered as an application route. */
if (getenv('OPMONI_BROWSER_FIXTURE') !== '1' || ! in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    exit(2);
}
require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = realpath((string) getenv('OPMONI_BROWSER_DIRECTORY'));
$expectedDirectory = realpath(__DIR__.'/../../../.superpowers/sdd/2026-10-17-platform-remaining/browser-fixture');
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== 'postgres'
    || config('database.connections.pgsql.database') !== 'opmoni_test'
    || DB::selectOne('select current_database() as name')->name !== 'opmoni_test'
    || ! $directory || $directory !== $expectedDirectory) {
    exit(3);
}
foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $database) {
    if ((int) config('database.redis.'.$connection.'.database') !== $database) {
        exit(4);
    }
}
config([
    'access.origin' => 'http://127.0.0.1:3316',
    'integra-contador.auth_url' => 'https://browser-serpro.invalid/authenticate',
    'integra-contador.auth_trusted_hosts' => ['browser-serpro.invalid'],
]);
Queue::fake();
Http::preventStrayRequests();
$modeFile = $directory.'/mode.json';
$mode = is_file($modeFile) ? json_decode(file_get_contents($modeFile), true, flags: JSON_THROW_ON_ERROR) : [];

if (PHP_SAPI === 'cli') {
    $command = $argv[1] ?? '';
    if (in_array($command, ['init', 'cleanup'], true)) {
        Artisan::call('migrate:fresh', ['--force' => true]);
        foreach (['default', 'cache', 'sessions'] as $connection) {
            Redis::connection($connection)->flushdb();
        }
    }
    if ($command === 'init') {
        $actors = [];
        foreach (['platform-only', 'ordinary', 'member-platform', 'revoked-platform'] as $label) {
            $actors[$label] = User::factory()->create([
                'name' => 'Browser '.$label,
                'email' => $label.'@example.test',
                'password' => Hash::make('fixture-only-password'),
                'is_super_admin' => $label !== 'ordinary',
            ]);
        }
        $accounts = [];
        foreach (['Alpha', 'Beta', 'Foreign', 'Suspended', 'Canceled'] as $label) {
            $accounts[$label] = Account::factory()->create([
                'name' => 'Browser '.$label, 'status' => $label === 'Suspended' ? 'suspended' : 'active',
            ]);
            if ($label === 'Canceled') {
                $accounts[$label]->subscription->update(['status' => 'canceled']);
            }
            if ($label !== 'Foreign') {
                AccountMembership::factory()->create([
                    'account_id' => $accounts[$label]->id, 'user_id' => $actors['member-platform']->id, 'role' => 'admin',
                ]);
            }
        }
        foreach (['ordinary', 'revoked-platform'] as $label) {
            AccountMembership::factory()->create([
                'account_id' => $accounts['Alpha']->id, 'user_id' => $actors[$label]->id, 'role' => 'admin',
            ]);
        }
        for ($index = 1; $index <= 12; $index++) {
            Account::factory()->create(['name' => sprintf('Browser Page %02d', $index)]);
            User::factory()->create(['name' => sprintf('Browser Reader %02d', $index), 'email' => 'reader-'.$index.'@example.test']);
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['CN' => 'BROWSER TEST:12345678000195', 'serialNumber' => '12345678000195'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 2);
        openssl_pkcs12_export($certificate, $bytes, $key, '');
        file_put_contents($directory.'/certificate.p12', $bytes);
        chmod($directory.'/certificate.p12', 0600);
        file_put_contents($directory.'/malformed.p12', 'invalid test-only PKCS12');
        chmod($directory.'/malformed.p12', 0600);
        $fixture = [
            'actors' => array_map(fn (User $user): int => $user->id, $actors),
            'accounts' => array_map(fn (Account $account): int => $account->id, $accounts),
            'certificate_document' => '12345678000195',
            'account_count' => Account::count(), 'user_count' => User::count(),
        ];
        file_put_contents($directory.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($directory.'/fixture.json', 0600);
        echo json_encode($fixture, JSON_THROW_ON_ERROR)."\n";
    } elseif ($command === 'revoke') {
        User::where('email', 'revoked-platform@example.test')->sole()->update(['is_super_admin' => false]);
        echo "sacrificial-platform-role-revoked\n";
    } elseif ($command === 'verify') {
        $snapshot = app(SerproCredentialLifecycle::class)->snapshot();
        $valid = $snapshot !== null && $snapshot->certificateBytes() === file_get_contents($directory.'/certificate.p12')
            && $snapshot->certificatePassword() === '' && $snapshot->document === '12345678000195';
        echo json_encode(['generated_certificate_bytes_password_document_match' => $valid], JSON_THROW_ON_ERROR)."\n";
        exit($valid ? 0 : 7);
    } elseif ($command === 'legacy-source') {
        $account = Account::where('name', 'Browser Alpha')->sole();
        app(CurrentTenant::class)->run($account->id, fn () => app(AccountCertificateVault::class)->replace(
            $account, new UploadedFile($directory.'/certificate.p12', 'certificate.p12', null, null, true), '',
        ));
        app(SerproConnectionManager::class)->save('browser-fixture-key', 'browser-fixture-secret', null, null, true);
        echo "legacy-source-created-through-production-vault-and-manager\n";
    } elseif ($command === 'mode') {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $allowedPaths = ['/api/v1/admin/summary', '/api/v1/admin/accounts', '/api/v1/admin/plans', '/api/v1/admin/subscriptions', '/api/v1/admin/users', '/api/v1/admin/support/logs', '/api/v1/serpro/connection'];
        $accountPath = isset($input['path']) && preg_match('#^/api/v1/admin/accounts/[1-9][0-9]*(/contracting-certificate)?$#', $input['path']) === 1;
        if (array_diff(array_keys($input), ['path', 'status', 'delay_ms', 'provider', 'empty_catalog', 'provider_delay_ms'])
            || (isset($input['path']) && ! in_array($input['path'], $allowedPaths, true) && ! $accountPath)
            || (isset($input['status']) && ! in_array($input['status'], [404, 500], true))
            || (isset($input['delay_ms']) && (! is_int($input['delay_ms']) || $input['delay_ms'] < 0 || $input['delay_ms'] > 4000))
            || (isset($input['provider_delay_ms']) && (! is_int($input['provider_delay_ms']) || $input['provider_delay_ms'] < 0 || $input['provider_delay_ms'] > 2000))
            || (isset($input['empty_catalog']) && (! is_bool($input['empty_catalog']) || ($input['path'] ?? '') !== '/api/v1/admin/plans'))
            || (isset($input['provider']) && ! in_array($input['provider'], ['ok', 'credential', 'provider', 'changed'], true))) {
            exit(5);
        }
        file_put_contents($modeFile, json_encode($input, JSON_THROW_ON_ERROR));
        chmod($modeFile, 0600);
        echo "test-mode-set\n";
    } elseif ($command === 'reset-mode' || $command === 'cleanup') {
        if (is_file($modeFile)) {
            unlink($modeFile);
        }
        echo "test-mode-cleared\n";
    } else {
        exit(6);
    }
    exit;
}

Http::fake(['https://browser-serpro.invalid/authenticate' => function () use ($mode) {
    if (isset($mode['provider_delay_ms'])) {
        usleep($mode['provider_delay_ms'] * 1000);
    }
    if (($mode['provider'] ?? '') === 'changed') {
        SerproConnection::sole()->increment('revision');
    }

    return match ($mode['provider'] ?? 'ok') {
        'credential' => Http::response([], 401),
        'provider' => Http::response([], 503),
        default => Http::response(['access_token' => 'browser-discard-access', 'jwt_token' => 'browser-discard-jwt', 'expires_in' => 3600]),
    };
}]);
$request = Request::capture();
if ($request->isMethod('GET') && $request->getPathInfo() === ($mode['path'] ?? null)) {
    if (isset($mode['delay_ms'])) {
        usleep($mode['delay_ms'] * 1000);
    }
    if (isset($mode['status'])) {
        response()->json(['message' => 'Falha de leitura simulada para aceitação.', 'code' => 'fixture_read_failure'], $mode['status'], ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
    if ($mode['empty_catalog'] ?? false) {
        response()->json([], 200, ['Cache-Control' => 'no-store, private'])->send();
        exit;
    }
}
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
file_put_contents($directory.'/requests.jsonl', json_encode([
    'method' => $request->method(), 'path' => $request->getPathInfo(), 'status' => $response->getStatusCode(),
], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
chmod($directory.'/requests.jsonl', 0600);
$response->send();
$kernel->terminate($request, $response);
