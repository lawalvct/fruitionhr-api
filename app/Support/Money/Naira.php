<?php

namespace App\Support\Money;

/**
 * Formats integer kobo for human-readable output (PDF/xlsx). Display only —
 * never use the float result for further arithmetic.
 */
class Naira
{
    /** Kobo → "₦1,234,567.89". */
    public static function format(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, 2);
    }

    /** Kobo → decimal naira as a float, for spreadsheet numeric cells. */
    public static function toNaira(int $kobo): float
    {
        return round($kobo / 100, 2);
    }
}
