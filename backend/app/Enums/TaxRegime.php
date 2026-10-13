<?php

namespace App\Enums;

enum TaxRegime: string
{
    case Mei = 'mei';
    case SimpleNational = 'simple_national';
    case PresumedProfit = 'presumed_profit';
    case ActualProfit = 'actual_profit';
    case Other = 'other';
    case NotApplicable = 'not_applicable';
}
