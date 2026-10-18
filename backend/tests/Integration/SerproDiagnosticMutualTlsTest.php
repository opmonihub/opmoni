<?php

namespace Tests\Integration;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class SerproDiagnosticMutualTlsTest extends TestCase
{
    public function test_real_mutual_tls_keeps_peer_verification_and_uses_pkcs12(): void
    {
        $directory = sys_get_temp_dir().'/opmoni-serpro-mtls-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $server = null;
        try {
            file_put_contents($directory.'/extensions.cnf', "[server]\nsubjectAltName=IP:127.0.0.1\nextendedKeyUsage=serverAuth\n[client]\nextendedKeyUsage=clientAuth\n");
            $run = function (array $arguments) use ($directory): void {
                $process = new Process($arguments, $directory);
                $process->mustRun();
            };
            $run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'ca.key', '-out', 'ca.pem', '-days', '1', '-subj', '/CN=Temporary Test CA']);
            foreach (['server', 'client'] as $name) {
                $run(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', $name.'.key', '-out', $name.'.csr', '-subj', '/CN='.($name === 'client' ? 'TEST:12345678000195' : 'localhost')]);
                $run(['openssl', 'x509', '-req', '-in', $name.'.csr', '-CA', 'ca.pem', '-CAkey', 'ca.key', '-CAcreateserial', '-out', $name.'.pem', '-days', '1', '-extfile', 'extensions.cnf', '-extensions', $name]);
            }
            $run(['openssl', 'pkcs12', '-export', '-in', 'client.pem', '-inkey', 'client.key', '-out', 'client.p12', '-passout', 'pass:fixture']);
            $fixture = __DIR__.'/serpro-mtls-fixture.php';
            $server = new Process([PHP_BINARY, $fixture, 'server', $directory], null, ['APP_ENV' => 'testing']);
            $server->start();
            $deadline = microtime(true) + 5;
            while (! is_file($directory.'/address') && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/address');
            $client = new Process([PHP_BINARY, '-d', 'curl.cainfo='.$directory.'/ca.pem', $fixture, 'client', $directory], null, ['APP_ENV' => 'testing']);
            $client->run();
            $this->assertTrue($client->isSuccessful(), $client->getErrorOutput().$client->getOutput());
            $server->wait();
            $this->assertTrue($server->isSuccessful(), $server->getErrorOutput());
            $this->assertSame('verified-client-certificate-and-protocol', file_get_contents($directory.'/result'));
        } finally {
            $server?->stop();
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
