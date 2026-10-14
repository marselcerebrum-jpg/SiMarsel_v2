<?php

namespace App\Enums;

enum TrendPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * Maximum date range in days, or null when the range is unlimited.
     */
    public function maxDays(): ?int
    {
        return match ($this) {
            self::Daily, self::Weekly => 366,
            self::Monthly => null,
        };
    }
}
