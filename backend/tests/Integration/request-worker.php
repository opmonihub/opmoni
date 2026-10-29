<?php

use App\Actions\AccessAccounts;
use App\Http\Middleware\AccessSecurity;
use App\Http\Middleware\SerializedAccessSession;
use App\Http\Middleware\TrustedIngress;
use App\Models\User;
use App\Support\AccessSession;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Integration\OfficeErpCommercialProcessTestCase;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if ($input['redis_failure'] ?? false) {
    $app->beforeBootstrapping(BootProviders::class, function (): void {
        config(['database.redis.cache.port' => 1]);
    });
}
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'opmoni_test'
    || config('database.redis.sessions.database') !== '15') {
    throw new RuntimeException('Integration worker requires isolated PostgreSQL and Redis stores.');
}

if (($input['database_operation'] ?? null) === 'reset-auth-revision') {
    OfficeErpCommercialProcessTestCase::assertIsolatedStores();
    DB::transaction(function () use ($input): void {
        $user = User::query()->findOrFail($input['user_id']);
        app(AccessAccounts::class)->advisory('email:'.mb_strtolower(trim($user->email)));
        $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $user->forceFill([
            'password' => Hash::make('Replacement password 123'),
            'access_auth_revision' => (int) $user->access_auth_revision + 1,
        ])->save();
        if (isset($input['ready'], $input['release'])) {
            file_put_contents($input['ready'], json_encode(['database_pid' => DB::selectOne('select pg_backend_pid() as pid')->pid]));
            $deadline = microtime(true) + 15;
            while (! file_exists($input['release'])) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Authentication reset fixture synchronization timed out.');
                }
                usleep(1000);
            }
        }
        if (isset($input['company_id'])) {
            DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        }
    });
    echo json_encode(['status' => 204]);
    exit;
}

if (isset($input['pause_after_password_proof'])) {
    OfficeErpCommercialProcessTestCase::assertIsolatedStores();
    $signals = $input['pause_after_password_proof'];
    $app->bind(AccessAccounts::class, fn () => new class($signals) extends AccessAccounts
    {
        public function __construct(private array $signals) {}

        public function authenticatePassword(string $email, string $password): array
        {
            $proof = parent::authenticatePassword($email, $password);
            file_put_contents($this->signals['ready'], json_encode(['revision' => $proof[1]]));
            $deadline = microtime(true) + 15;
            while (! file_exists($this->signals['release'])) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Password proof fixture synchronization timed out.');
                }
                usleep(1000);
            }

            return $proof;
        }
    });
}

if (isset($input['started'])) {
    file_put_contents($input['started'], json_encode(['database_pid' => DB::selectOne('select pg_backend_pid() as pid')->pid]));
}

if (($input['database_operation'] ?? null) === 'hold-account') {
    DB::transaction(function () use ($input): void {
        DB::table('accounts')->where('id', $input['account_id'])->lockForUpdate()->first();
        touch($input['ready']);
        $deadline = microtime(true) + 20;
        while (! file_exists($input['release'])) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Row-lock synchronization timed out.');
            }
            usleep(1000);
        }
    });
    echo json_encode(['status' => 204]);
    exit;
}
if (($input['database_operation'] ?? null) === 'hold-erp-company') {
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        touch($input['ready']);
        $deadline = microtime(true) + 20;
        while (! file_exists($input['release'])) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('ERP company row-lock synchronization timed out.');
            }
            usleep(1000);
        }
    });
    echo json_encode(['status' => 204]);
    exit;
}
if ($input['session_write_failure'] ?? false) {
    $handler = $app->make('session')->driver()->getHandler();
    $app->make('session')->driver()->setHandler(new class($handler) implements SessionHandlerInterface
    {
        public function __construct(private SessionHandlerInterface $handler) {}

        public function open(string $path, string $name): bool
        {
            return $this->handler->open($path, $name);
        }

        public function close(): bool
        {
            return $this->handler->close();
        }

        public function read(string $id): string|false
        {
            return $this->handler->read($id);
        }

        public function write(string $id, string $data): bool
        {
            throw new RuntimeException('Test session persistence unavailable');
        }

        public function destroy(string $id): bool
        {
            return $this->handler->destroy($id);
        }

        public function gc(int $max_lifetime): int|false
        {
            return $this->handler->gc($max_lifetime);
        }
    });
}
if (isset($input['session_operation'])) {
    $encrypted = $input['cookies']['opmoni-session'];
    $sessionId = CookieValuePrefix::remove($app->make('encrypter')->decrypt($encrypted, false));
    $handler = $app->make('session')->driver()->getHandler();
    $payload = $handler->read($sessionId);
    if ($input['session_operation'] === 'expire') {
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $data['access_deadline'] = time() - 1;
        $handler->write($sessionId, json_encode($data, JSON_THROW_ON_ERROR));
    } elseif ($input['session_operation'] === 'stale-save') {
        touch($input['ready']);
        $deadline = microtime(true) + 10;
        while (! file_exists($input['release'])) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Stale-save synchronization timed out.');
            }
            usleep(1000);
        }
        $handler->write($sessionId, $payload);
    }
    echo json_encode(['status' => 204]);
    exit;
}
if ($input['fixture'] ?? false) {
    config(['access.lock_seconds' => 1]);
    Route::post('/api/v1/integration/lease', function (Request $request) {
        return DB::transaction(function () use ($request) {
            sleep(2);
            AccessSession::assertOwnership($request);
            DB::table('accounts')->update(['name' => 'Forbidden expired lease write']);

            return response()->noContent();
        });
    })->middleware([
        TrustedIngress::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        SerializedAccessSession::class,
        AccessSecurity::class,
        'auth',
    ]);
}
if (isset($input['barrier'])) {
    if (isset($input['bootstrap_ready'])) {
        touch($input['bootstrap_ready']);
    }
    $deadline = microtime(true) + 60;
    while (! file_exists($input['barrier'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker request barrier timed out.');
        }
        usleep(1000);
    }
}

if (($input['database_operation'] ?? null) === 'generate-recurrences') {
    $app->instance('request', Request::create('http://localhost:3000'));
    $status = Artisan::call('work:generate-recurrences', ['--month' => $input['month']]);
    echo json_encode(['status' => $status === 0 ? 200 : 500]);
    DB::disconnect();
    exit;
}

if (($input['database_operation'] ?? null) === 'suspend-erp-company') {
    DB::table('erp_companies')->where('id', $input['company_id'])->update(['status' => 'suspended', 'updated_at' => now()]);
    echo json_encode(['status' => 204]);
    DB::disconnect();
    exit;
}

if (($input['database_operation'] ?? null) === 'remove-erp-membership') {
    DB::table('erp_company_memberships')
        ->where('erp_company_id', $input['company_id'])
        ->where('user_id', $input['user_id'])
        ->delete();
    echo json_encode(['status' => 204]);
    DB::disconnect();
    exit;
}

$server = [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ORIGIN' => 'http://localhost:3000',
    'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key',
    'HTTP_X_OPMONI_CLIENT_IP' => $input['ip'] ?? '127.0.0.1',
    'HTTP_X_OPMONI_CLIENT_PROTO' => 'http',
];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$diagnosticExceptionTypes = [];
if ($input['diagnose_errors'] ?? false) {
    $app->make(ExceptionHandler::class)->reportable(function (Throwable $error) use (&$diagnosticExceptionTypes): void {
        $diagnosticExceptionTypes[] = $error::class;
    });
}
$request = Request::create(
    'http://localhost:3000'.$input['path'],
    $input['method'] ?? 'GET',
    [],
    $input['cookies'] ?? [],
    [],
    $server,
    json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR),
);
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode([
    'status' => $response->getStatusCode(),
    'exception_types' => $diagnosticExceptionTypes,
    'body' => json_decode($response->getContent(), true),
    'cookies' => $cookies,
    'cookie_attributes' => array_map(fn ($cookie) => [
        'name' => $cookie->getName(), 'http_only' => $cookie->isHttpOnly(),
        'secure' => $cookie->isSecure(), 'same_site' => $cookie->getSameSite(),
        'path' => $cookie->getPath(), 'domain' => $cookie->getDomain(),
    ], $response->headers->getCookies()),
    'cache_control' => $response->headers->get('Cache-Control'),
    'retry_after' => $response->headers->get('Retry-After'),
], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
DB::disconnect();
