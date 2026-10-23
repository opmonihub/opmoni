<?php

namespace App\Erp\Fiscal;

use NFePHP\Common\Soap\SoapInterface;

class NfePhpSoapTransportFactory
{
    public function make(): SoapInterface
    {
        return new NfePhpPrivateSoapCurl;
    }
}
