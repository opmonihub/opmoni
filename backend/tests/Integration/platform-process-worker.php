<?php

use App\Actions\AccessAccounts;
use App\Actions\EnsureAccountSubscription;
use App\Http\Middleware\ResolveAccount;
use App\Models\Account;
use App\Models\SerproConnection;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountCertificateVault;
use App\Services\AccountContractingCertificateVault;
use App\Services\SerproConnectionManager;
use App\Services\SerproCredentialLifecycle;
use App\Services\SerproException;
use App\Services\SerproTokenProvider;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\MemberAccessConcurrencyTest;
use Tests\Integration\PlatformProcessTestCase;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
MemberAccessConcurrencyTest::assertIsolatedEnvironment();
Http::preventStrayRequests();
Queue::fake();
$app->instance('request', Request::create('http://localhost:3000'));
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$base = $input['base'];
config(['filesystems.disks.local.root' => dirname($base), 'integra-contador.temp_dir' => dirname($base)]);
$write = static function (string $signal, array $data = []) use ($base): void {
    $temporary = $base.'.'.$signal.'.tmp';
    file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR));
    chmod($temporary, 0600);
    rename($temporary, $base.'.'.$signal);
};
$await = static function (string $signal, float $seconds = 12) use ($base): void {
    $deadline = microtime(true) + $seconds;
    while (! file_exists($base.'.'.$signal)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Platform worker barrier timed out.');
        }
        usleep(5000);
    }
};
$identity = ['pid' => getmypid(), 'database_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid];
$write('ready', $identity);
$httpCalls = 0;
$materialized = [];
$discoveries = 0;
$paused = false;
$httpChecks = [];
$limiterChecks = [];
$limiter = RateLimiter::limiter('serpro-connectivity');
RateLimiter::for('serpro-connectivity', function ($request) use ($limiter, &$limiterChecks): array {
    $limits = $limiter($request);
    $limiterChecks = [
        'actor' => $request->user()->getAuthIdentifier(),
        'trusted_ip_present' => $request->attributes->has('access.client_ip'),
        'attempts' => array_map(fn ($limit) => RateLimiter::attempts(md5('serpro-connectivity'.$limit->key)), $limits),
    ];

    return $limits;
});
$sessionLock = null;
$result = [];
$connectionWrites = [];
SerproConnection::saved(function (SerproConnection $connection) use (&$connectionWrites): void {
    $connectionWrites[] = ['id' => $connection->id, 'revision' => $connection->revision];
});
try {
    if ($input['start_gate'] ?? false) {
        $await('start');
    }
    if ($input['rollback_provisioning'] ?? false) {
        $app->bind(EnsureAccountSubscription::class, fn () => new class($write, $await) extends EnsureAccountSubscription
        {
            public function __construct(private Closure $write, private Closure $await) {}

            public function handle(Account $account): Subscription
            {
                parent::handle($account);
                ($this->write)('held', ['transaction_level' => DB::transactionLevel()]);
                ($this->await)('release');
                throw new RuntimeException('Injected provisioning failure');
            }
        });
    }
    DB::listen(function ($query) use ($input, $write, $await, &$paused, &$discoveries): void {
        $discovery = $query->sql === 'select "contracting_account_id" from "serpro_connections" limit 1';
        if ($discovery) {
            $discoveries++;
        }
        $pause = $input['pause'] ?? null;
        $matches = match ($pause) {
            'account' => str_starts_with($query->sql, 'select * from "accounts"') && str_ends_with($query->sql, 'for update') && in_array($input['account'], $query->bindings),
            'scan' => str_starts_with($query->sql, 'select * from "accounts" where not exists') && str_contains($query->sql, '"subscriptions"') && str_contains($query->sql, 'limit 200'),
            'advisory' => $query->sql === 'SELECT pg_advisory_xact_lock(?)' && $query->bindings === [SerproCredentialLifecycle::ADVISORY_LOCK],
            'connection' => $query->sql === 'select * from "serpro_connections" limit 1 for update',
            'source-update' => str_starts_with($query->sql, 'update "account_contracting_certificates" set ') && end($query->bindings) === $input['source_id'],
            'discovery' => $discovery,
            default => false,
        };
        if (! $paused && $matches) {
            $paused = true;
            $write('held', ['transaction_level' => DB::transactionLevel(), 'barrier' => $pause]);
            $await('release');
            if ($input['rollback_source'] ?? false) {
                throw new RuntimeException('Injected source failure');
            }
        }
    });
    Http::fake(function ($request, $options) use ($input, $write, $await, &$httpCalls, &$materialized, &$httpChecks): mixed {
        $httpCalls++;
        $path = $options['cert'][0];
        $materialized[] = $path;
        $expected = $input['expected'];
        $httpChecks = [
            'transaction_scope' => DB::transactionLevel() === (str_starts_with($input['action'], 'tenant-') ? 1 : 0),
            'basic_matches' => ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode($expected['key'].':'.$expected['secret']),
            'certificate_matches' => hash_file('sha256', $path) === $expected['certificate']['digest'],
            'password_matches' => $options['cert'][1] === $expected['certificate']['password'],
            'private_file' => (fileperms($path) & 0777) === 0600,
            'private_directory' => (fileperms(dirname($path)) & 0777) === 0700,
            'timeout_bounded' => $input['action'] !== 'diagnostic' || ((float) $options['timeout'] <= 8 && (float) $options['connect_timeout'] <= 3),
        ];
        if (in_array(false, $httpChecks, true)) {
            throw new RuntimeException('Captured HTTP material or transaction invariant failed.');
        }
        if ($input['block_http'] ?? false) {
            $write('http-entered', $httpChecks);
            $await('http-release', 6);
        }
        $label = $input['token_label'] ?? 'fixture';

        return Http::response(['access_token' => 'synthetic-access-'.$label, 'jwt_token' => 'synthetic-jwt-'.$label, 'expires_in' => 3600], $input['upstream_status'] ?? 200);
    });
    $upload = isset($input['certificate']) ? new UploadedFile($input['certificate']['path'], 'contracting.p12', 'application/octet-stream', null, true) : null;
    $action = $input['action'];
    if (in_array($action, ['save-http', 'diagnostic', 'suspend'], true)) {
        config(['session.cookie' => 'opmoni-session']);
        $session = app('session')->driver();
        $session->start();
        $session->put(['access_deadline' => time() + 43200, 'access_authenticated' => true, app('auth')->guard('web')->getName() => $input['actor']]);
        $token = $session->token();
        $session->save();
        $sessionLock = 'access-session:'.hash('sha256', $session->getId());
        $write('session', ['lock_key' => $sessionLock]);
        $cookie = app('encrypter')->encrypt(CookieValuePrefix::create('opmoni-session', app('encrypter')->getKey()).$session->getId(), false);
        [$method, $path, $body] = match ($action) {
            'save-http' => ['PUT', '/api/v1/serpro/connection', ['consumer_key' => $input['key'], 'consumer_secret' => $input['secret'], 'certificate_source' => 'upload', 'password' => $input['certificate']['password']]],
            'suspend' => ['PATCH', '/api/v1/admin/accounts/'.$input['account'], ['status' => 'suspended']],
            default => ['POST', '/api/v1/serpro/connectivity', []],
        };
        $server = [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_CSRF_TOKEN' => $token,
            'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key',
            'HTTP_X_OPMONI_CLIENT_IP' => '2001:db8:'.dechex($input['actor'] >> 16).':'.dechex($input['actor'] & 65535).'::1', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http',
        ];
        $request = Request::create('http://localhost:3000'.$path, $method, $body, ['opmoni-session' => $cookie], $upload === null ? [] : ['certificate' => $upload], $server);
        $started = microtime(true);
        $response = $kernel->handle($request);
        $decoded = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $result = ['status' => $response->getStatusCode(), 'code' => $decoded['code'] ?? null, 'ok' => $decoded['data']['ok'] ?? null, 'failed_element' => $decoded['data']['failed_element'] ?? null, 'no_store' => str_contains($response->headers->get('Cache-Control', ''), 'no-store'), 'elapsed' => microtime(true) - $started];
        $kernel->terminate($request, $response);
    } elseif ($action === 'initialize') {
        $result = ['status' => Artisan::call('platform:initialize') === 0 ? 200 : 500];
    } elseif ($action === 'issue') {
        [$account] = app(AccessAccounts::class)->issue(['name' => 'Issued office', 'owner_name' => 'Issued owner', 'login_email' => $input['email']], $input['account'] ?? null);
        $result = ['status' => 201, 'account' => $account->id];
    } elseif ($action === 'save') {
        $connection = app(SerproConnectionManager::class)->save($input['key'], $input['secret'], $upload, $input['certificate']['password'] ?? null, false, $input['source'] ?? 'upload', $input['account'] ?? null);
        $result = ['status' => 200, 'connection' => $connection->id, 'revision' => $connection->revision];
    } elseif ($action === 'replace') {
        $source = app(AccountContractingCertificateVault::class)->replace(Account::findOrFail($input['account']), $upload, $input['certificate']['password']);
        $result = ['status' => 200, 'source_revision' => $source->revision];
    } elseif ($action === 'legacy-remove') {
        app(CurrentTenant::class)->run($input['account'], fn () => app(AccountCertificateVault::class)->remove(Account::findOrFail($input['account'])));
        $result = ['status' => 200];
    } elseif ($action === 'snapshot' || $action === 'snapshot-watch') {
        $snapshot = app(SerproCredentialLifecycle::class)->snapshot();
        $result = ['status' => 200, 'tuple' => PlatformProcessTestCase::tuple($snapshot, $input['expected'])];
        if ($action === 'snapshot-watch') {
            $write('held', ['generation' => $snapshot->generation()]);
            $await('release');
            $result['immutable_tuple'] = PlatformProcessTestCase::tuple($snapshot, $input['expected']);
            $result['still_current'] = app(SerproCredentialLifecycle::class)->isCurrent($snapshot);
        }
    } elseif ($action === 'tenant-pair' || $action === 'tenant-verify') {
        $request = Request::create('http://localhost:3000/api/v1/serpro/monitoring/fixture', 'GET', server: ['HTTP_X_ACCOUNT_ID' => (string) $input['tenant']]);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('current_account_id', $input['tenant']);
        $app->instance('request', $request);
        $request->setUserResolver(fn () => User::findOrFail($input['actor']));
        $response = app(ResolveAccount::class)->handle($request, function () use ($action, $input, $write, $await): Response {
            $pair = app(SerproTokenProvider::class)->{substr($action, 7)}();
            if ($input['pause_cached'] ?? false) {
                $write('cached', ['transaction_level' => DB::transactionLevel()]);
                $await('release');
            }

            return response()->json(['pair_matches' => $pair === null || $pair->jwtToken() === 'synthetic-jwt-'.($input['token_label'] ?? 'fixture')]);
        });
        $result = ['status' => $response->getStatusCode(), ...json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
    } elseif ($action === 'pair' || $action === 'verify') {
        $pair = app(SerproTokenProvider::class)->{$action}();
        $result = ['status' => 200, 'pair_matches' => $pair === null || $pair->jwtToken() === 'synthetic-jwt-'.($input['token_label'] ?? 'fixture')];
    } elseif ($action === 'take-session-lock') {
        $lock = Cache::lock($input['lock_key'], 30);
        $lock->forceRelease();
        $acquired = $lock->get();
        $write('held', ['acquired' => $acquired]);
        $await('release');
        $lock->release();
        $result = ['status' => $acquired ? 200 : 500];
    } else {
        throw new LogicException('Unknown platform worker action.');
    }
} catch (ValidationException) {
    $result = ['status' => 422];
} catch (SerproException $exception) {
    $result = ['status' => 502, 'failure' => $exception->failure->value];
} catch (RuntimeException $exception) {
    $injected = in_array($exception->getMessage(), ['Injected provisioning failure', 'Injected source failure'], true);
    $result = $injected ? ['status' => 500, 'injected' => true] : ['unexpected_exception' => $exception::class];
} catch (Throwable $exception) {
    $result = ['unexpected_exception' => $exception::class];
}
$result += ['http_calls' => $httpCalls, 'http_checks' => $httpChecks, 'materialized_cleaned' => array_all($materialized, fn ($path) => ! file_exists($path)), 'limiter_checks' => $limiterChecks, 'discoveries' => $discoveries, 'connection_writes' => $connectionWrites];
if ($limiterChecks !== [] && ! $limiterChecks['trusted_ip_present']) {
    $result['unexpected_exception'] = 'MissingTrustedIngressAtLimiter';
}
echo json_encode($result, JSON_THROW_ON_ERROR);
DB::disconnect();
