<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Fiscal\ErpFiscalConfigurationRevision;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapInterface;
use RuntimeException;

final class NfePhpToolsFactory
{
    public function make(string $model, string $environment, string $accessKey, ErpEstablishment $issuer, ErpFiscalConfigurationRevision $revision, string $certificateBytes, string $password, SoapInterface $soap): object
    {
        if (! in_array($model, ['55', '65', '57', '58'], true)
            || $environment !== $revision->environment
            || strlen($accessKey) === 44 && ! hash_equals((string) $issuer->cnpj, substr($accessKey, 6, 14))) {
            throw new RuntimeException('Escopo fiscal fixado incompatível.');
        }

        $configuration = $revision->configuration;
        $issuerConfiguration = $configuration['issuer'] ?? [];
        if (! is_array($issuerConfiguration) || ! is_string($issuerConfiguration['uf'] ?? null)) {
            throw new RuntimeException('Configuração fiscal fixada inválida.');
        }
        $nfePhpConfiguration = [
            'atualizacao' => now()->toIso8601String(),
            'tpAmb' => $environment === 'production' ? 1 : 2,
            'razaosocial' => (string) $issuer->name,
            'siglaUF' => (string) $issuerConfiguration['uf'],
            'cnpj' => (string) $issuer->cnpj,
            'schemes' => match ($model) {
                '55', '65' => 'PL_009_V4',
                '57' => 'PL_CTe_400',
                '58' => 'PL_MDFe_300',
            },
            'versao' => match ($model) {
                '55', '65' => '4.00',
                '57' => '4.00',
                '58' => '3.00',
            },
        ];

        $certificate = Certificate::readPfx($certificateBytes, $password);
        $toolsClass = match ($model) {
            '55', '65' => NfePhpNfeSignedEventTools::class,
            '57' => NfePhpCteSignedEventTools::class,
            '58' => NfePhpMdfeSignedEventTools::class,
        };
        if (! class_exists($toolsClass)) {
            throw new RuntimeException('Pacote NFePHP do modelo indisponível.');
        }

        $tools = new $toolsClass(json_encode($nfePhpConfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $certificate);
        $tools->loadSoapClass($soap);
        if (in_array($model, ['55', '65'], true)) {
            $tools->model((int) $model);
        }
        if ($model === '65') {
            $tools->forceQRCodeVersion('300');
        }

        return $tools;
    }
}
