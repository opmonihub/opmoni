<?php

use App\Models\Erp\ErpOperationReceipt;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (getenv('OPERATION_IDENTITY_ISOLATED') !== 'task1-20261018' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    throw new RuntimeException('Dedicated operation identity stores required.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['erp.operating_writes_enabled' => true, 'app.name' => 'Opmoni', 'session.cookie' => 'opmoni-session']);
$label = $input['label'] ?? 'operation-'.getmypid();
DB::select("select set_config('application_name', ?, false)", [$label]);
if (isset($input['ready'])) {
    DB::connection()->beforeExecuting(function (string $query) use ($input): void {
        if (str_contains($query, 'erp_companies') && str_contains($query, 'for update')) {
            file_put_contents($input['ready'], (string) getmypid());
        }
    });
}
if ($input['lose_lease_after_receipt'] ?? false) {
    ErpOperationReceipt::created(function (): void {
        request()->attributes->get('access.session_lock')->forceRelease();
    });
}
if ($input['lose_lease_after_controller'] ?? false) {
    $app['router']->getRoutes()->getByName('erp.payments.allocate')->middleware(OperationIdentityLoseLeaseAfterController::class);
}
if ($input['fail_after_receipt'] ?? false) {
    ErpOperationReceipt::created(function (): void {
        abort(503, 'Injected receipt failure');
    });
}
if ($input['session_write_failure'] ?? false) {
    $session = $app->make('session')->driver();
    $session->setHandler(new class($session->getHandler()) implements SessionHandlerInterface
    {
        public function __construct(private SessionHandlerInterface $inner) {}

        public function open(string $path, string $name): bool
        {
            return $this->inner->open($path, $name);
        }

        public function close(): bool
        {
            return $this->inner->close();
        }

        public function read(string $id): string|false
        {
            return $this->inner->read($id);
        }

        public function write(string $id, string $data): bool
        {
            throw new RuntimeException('Injected response loss after commit');
        }

        public function destroy(string $id): bool
        {
            return $this->inner->destroy($id);
        }

        public function gc(int $max_lifetime): int|false
        {
            return $this->inner->gc($max_lifetime);
        }
    });
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key', 'HTTP_X_OPMONI_CLIENT_IP' => '127.0.0.1', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [], $input['cookies'] ?? [], [], $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'cookies' => $cookies, 'cache_control' => $response->headers->get('Cache-Control'), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
DB::disconnect();

class OperationIdentityLoseLeaseAfterController
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $request->attributes->get('access.session_lock')->forceRelease();

        return $response;
    }
}
