<?php

namespace App\Erp\Fiscal;

use NFePHP\NFe\Tools;

final class NfePhpNfeSignedEventTools extends Tools
{
    public function getTypeOfPersonFromCertificate(): string
    {
        return strlen((string) ($this->config->cnpj ?? '')) === 14 ? 'J' : 'F';
    }
}
