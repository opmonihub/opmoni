<?php

namespace App\Erp\Fiscal;

use NFePHP\Common\Exception\SoapException;
use NFePHP\Common\Soap\SoapCurl;
use NFePHP\Common\Validator;
use RuntimeException;
use Throwable;

/**
 * NFePHP transport with per-request certificate files and peer verification.
 * The pinned SoapCurl leaves CURLOPT_SSL_VERIFYPEER disabled even when its
 * security switch is enabled, so this implementation keeps its envelope and
 * response behavior while applying strict TLS validation.
 */
class NfePhpPrivateSoapCurl extends SoapCurl
{
    public function send($url, $operation = '', $action = '', $soapver = SOAP_1_2, $parameters = [], $namespaces = [], $request = '', $soapheader = null)
    {
        $directory = sys_get_temp_dir().'/opmoni-fiscal-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar diretório privado do certificado fiscal.');
        }
        chmod($directory, 0700);

        try {
            $this->certsdir = '';
            $this->prifile = null;
            $this->pubfile = null;
            $this->certfile = null;
            $this->filesystem = null;
            $this->setTemporaryFolder($directory);
            $this->saveTemporarilyKeyFiles();
            foreach (glob($directory.'/certs/*') ?: [] as $path) {
                chmod($path, 0600);
            }

            return $this->sendWithPrivateFiles($url, $operation, $action, $soapver, $parameters, $namespaces, $request, $soapheader);
        } finally {
            $this->removeTemporarilyFiles();
            foreach (glob($directory.'/certs/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory.'/certs');
            @rmdir($directory);
            $this->certsdir = '';
            $this->prifile = null;
            $this->pubfile = null;
            $this->certfile = null;
            $this->filesystem = null;
        }
    }

    protected function sendWithPrivateFiles($url, $operation, $action, $soapver, $parameters, $namespaces, $request, $soapheader): string
    {
        $envelope = $this->makeEnvelopeSoap($request, $namespaces, $soapver, $soapheader);
        $headers = [
            'Content-Type: application/soap+xml;charset=utf-8;'.($action !== '' ? 'action='.$action : ''),
            'Content-length: '.strlen($envelope),
        ];
        $this->requestHead = implode("\n", $headers);
        $this->requestBody = $envelope;
        $response = '';
        $httpcode = 0;

        try {
            $curl = curl_init();
            $this->setCurlProxy($curl);
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => $this->soaptimeout,
                CURLOPT_TIMEOUT => $this->soaptimeout + 20,
                CURLOPT_HEADER => true,
                CURLOPT_HTTP_VERSION => $this->httpver,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSLVERSION => $this->soapprotocol,
                CURLOPT_SSLCERT => $this->tempdir.$this->certfile,
                CURLOPT_SSLKEY => $this->tempdir.$this->prifile,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if (! empty($this->security_level)) {
                curl_setopt($curl, CURLOPT_SSL_CIPHER_LIST, $this->security_level);
            }
            if (! empty($this->casefaz) && is_file($this->casefaz)) {
                curl_setopt($curl, CURLOPT_CAINFO, $this->casefaz);
            }
            if (! empty($this->temppass)) {
                curl_setopt($curl, CURLOPT_KEYPASSWD, $this->temppass);
            }
            $response = curl_exec($curl);
            $this->soaperror = curl_error($curl);
            $this->soaperror_code = curl_errno($curl);
            $info = curl_getinfo($curl);
            if (is_array($info)) {
                $this->soapinfo = $info;
            }
            $headsize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $httpcode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response = is_string($response) ? $response : '';
            $this->responseHead = trim(substr($response, 0, $headsize));
            $this->responseBody = trim(substr($response, $headsize));
            $this->saveDebugFiles($operation, $this->requestHead."\n".$this->requestBody, $this->responseHead."\n".$this->responseBody);
        } catch (Throwable $exception) {
            throw SoapException::unableToLoadCurl($exception->getMessage());
        }
        if ($this->soaperror !== '') {
            throw SoapException::soapFault($this->soaperror.' ['.$url.']', (int) ($this->soaperror_code ?: 7));
        }
        if ($httpcode !== 200) {
            throw SoapException::soapFault('Falha HTTP no transporte SOAP.', $httpcode ?: 52);
        }
        if ($this->responseBody === '' || ! Validator::isXML($this->responseBody)) {
            throw SoapException::soapFault('Retorno do transporte SOAP inválido.', 99);
        }

        return $this->responseBody;
    }

    protected function setCurlProxy(&$curl)
    {
        if ($this->proxyIP !== '') {
            curl_setopt($curl, CURLOPT_HTTPPROXYTUNNEL, 1);
            curl_setopt($curl, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($curl, CURLOPT_PROXY, $this->proxyIP.':'.$this->proxyPort);
            if ($this->proxyUser !== '') {
                curl_setopt($curl, CURLOPT_PROXYUSERPWD, $this->proxyUser.':'.$this->proxyPass);
                curl_setopt($curl, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
            }
        }
    }
}
