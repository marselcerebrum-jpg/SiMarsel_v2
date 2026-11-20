<?php

namespace App\Enums;

/**
 * The period presets of the Analisis Performa filter.
 * LastYear is the "Tahun Lalu" option: this year so far, compared with the same part of last year.
 */
enum PeriodPreset: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case DateRange = 'date_range';
    case LastYear = 'last_year';

    /**
     * The number of full days up to yesterday, for the rolling presets.
     */
    public function rollingDays(): ?int
    {
        return match ($this) {
            self::Last7Days => 7,
            self::Last30Days => 30,
            self::Last90Days => 90,
            default => null,
        };
    }
}
