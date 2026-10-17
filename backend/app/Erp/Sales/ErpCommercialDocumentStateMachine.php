<?php

namespace App\Erp\Sales;

use InvalidArgumentException;

final class ErpCommercialDocumentStateMachine
{
    public const QUOTE_DRAFT = 'draft';

    public const QUOTE_CONVERTED = 'converted';

    public const ORDER_CONFIRMED = 'confirmed';

    public const SALE_DRAFT = 'draft';

    public const SALE_POSTED = 'posted';

    public function convertQuote(string $status): string
    {
        if ($status !== self::QUOTE_DRAFT) {
            throw new InvalidArgumentException('Only draft quotes can be converted.');
        }

        return self::QUOTE_CONVERTED;
    }

    public function postSale(string $status): string
    {
        if ($status !== self::SALE_DRAFT) {
            throw new InvalidArgumentException('Only draft sales can be posted.');
        }

        return self::SALE_POSTED;
    }
}
