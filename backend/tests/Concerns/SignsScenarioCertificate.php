<?php

namespace Tests\Concerns;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Native OpenSSL uses wall time; scenario clocks must also fall in certificate validity. */
trait SignsScenarioCertificate
{
    protected function signScenarioCertificate(OpenSSLCertificateSigningRequest $csr, OpenSSLAsymmetricKey $key): OpenSSLCertificate
    {
        $directory = sys_get_temp_dir().'/opmoni-scenario-cert-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            openssl_csr_export($csr, $request);
            openssl_pkey_export($key, $privateKey);
            $bootstrap = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
            openssl_x509_export($bootstrap, $certificate);
            file_put_contents($directory.'/request.pem', $request);
            file_put_contents($directory.'/key.pem', $privateKey);
            file_put_contents($directory.'/certificate.pem', $certificate);
            file_put_contents($directory.'/index', '');
            file_put_contents($directory.'/serial', '01');
            file_put_contents($directory.'/config.cnf', "[ca]\ndefault_ca=test\n[test]\ndatabase=$directory/index\nnew_certs_dir=$directory\nserial=$directory/serial\nprivate_key=$directory/key.pem\ncertificate=$directory/certificate.pem\ndefault_md=sha256\npolicy=policy\n[policy]\ncommonName=supplied\nserialNumber=optional\ncountryName=optional\nstateOrProvinceName=optional\nlocalityName=optional\norganizationName=optional\norganizationalUnitName=optional\nemailAddress=optional\n");
            $clocks = [time(), Carbon::now()->timestamp, CarbonImmutable::now()->timestamp];
            $process = new Process(['openssl', 'ca', '-selfsign', '-batch', '-notext', '-config', $directory.'/config.cnf',
                '-in', $directory.'/request.pem', '-out', $directory.'/signed.pem',
                '-startdate', gmdate('YmdHis', min($clocks) - 366 * 86400).'Z',
                '-enddate', gmdate('YmdHis', max($clocks) + 366 * 86400).'Z']);
            $process->mustRun();
            $signed = openssl_x509_read(file_get_contents($directory.'/signed.pem'));
            if ($signed === false) {
                throw new RuntimeException('Scenario certificate signing failed.');
            }

            return $signed;
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
