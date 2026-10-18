<?php

use App\Services\SerproCredentialSnapshot;
use App\Services\SerproDiagnosticAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

// Explicit test harness, never loaded by application routing.
if (getenv('APP_ENV') !== 'testing' || PHP_SAPI !== 'cli') {
    exit(2);
}
$directory = $argv[2];
if ($argv[1] === 'server') {
    $context = stream_context_create(['ssl' => [
        'local_cert' => $directory.'/server.pem', 'local_pk' => $directory.'/server.key',
        'cafile' => $directory.'/ca.pem', 'verify_peer' => true, 'verify_peer_name' => false,
        'allow_self_signed' => false, 'capture_peer_cert' => true,
    ]]);
    $server = stream_socket_server('tls://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    file_put_contents($directory.'/address', stream_socket_get_name($server, false));
    $client = stream_socket_accept($server, 15);
    if (! $client) {
        exit(3);
    }
    $peer = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
    $headers = '';
    while (($line = fgets($client)) !== false && $line !== "\r\n") {
        $headers .= $line;
    }
    preg_match('/Content-Length: (\d+)/i', $headers, $match);
    $body = fread($client, (int) ($match[1] ?? 0));
    $valid = $peer !== null && str_contains($headers, 'Authorization: Basic '.base64_encode('test-key:test-secret'))
        && str_contains($headers, 'Role-Type: TERCEIROS') && $body === 'grant_type=client_credentials';
    $json = json_encode(['access_token' => 'discard-local-access', 'jwt_token' => 'discard-local-jwt']);
    fwrite($client, 'HTTP/1.1 '.($valid ? '200 OK' : '400 Bad Request')."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: close\r\n\r\n".$json);
    fclose($client);
    fclose($server);
    file_put_contents($directory.'/result', $valid ? 'verified-client-certificate-and-protocol' : 'invalid');
    exit($valid ? 0 : 4);
}
require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['integra-contador.auth_url' => 'https://'.trim(file_get_contents($directory.'/address')).'/authenticate', 'integra-contador.auth_trusted_hosts' => ['127.0.0.1']]);
$encrypt = Crypt::encryptString(...);
$snapshot = new SerproCredentialSnapshot(1, 1, 'upload', null, null, null, null, '12345678000195', CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addDay(), null, [
    'key' => $encrypt('test-key'), 'secret' => $encrypt('test-secret'),
    'certificate' => $encrypt(file_get_contents($directory.'/client.p12')), 'password' => $encrypt('fixture'),
], 'test-generation');
$app->make(SerproDiagnosticAuthenticator::class)->authenticate($snapshot);
echo "verified-server-certificate-and-diagnostic-success\n";
