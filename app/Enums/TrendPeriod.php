<?php

namespace App\Enums;

use App\Support\Period\ResolvedPeriod;

/**
 * The trend granularity: one bucket per hour, day, week (Monday - Sunday), or calendar month.
 */
enum TrendPeriod: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * Maximum date range in days, or null when the range is unlimited.
     */
    public function maxDays(): ?int
    {
        return match ($this) {
            self::Hourly => 31,
            self::Daily, self::Weekly => 366,
            self::Monthly => null,
        };
    }

    /**
     * The granularities of the older single-metric trend, which reads daily totals only.
     *
     * @return list<self>
     */
    public static function dailyBased(): array
    {
        return [self::Daily, self::Weekly, self::Monthly];
    }

    /**
     * The default granularity of a resolved period: one day per hour, 7 and 30 days per day,
     * 90 days per week, and Tahun Lalu per month. Other presets follow the number of active days.
     */
    public static function defaultFor(ResolvedPeriod $period): self
    {
        return match ($period->preset) {
            PeriodPreset::Today, PeriodPreset::Yesterday => self::Hourly,
            PeriodPreset::Last7Days, PeriodPreset::Last30Days => self::Daily,
            PeriodPreset::Last90Days => self::Weekly,
            PeriodPreset::LastYear => self::Monthly,
            default => match (true) {
                $period->active->days() === 1 => self::Hourly,
                $period->active->days() <= 31 => self::Daily,
                $period->active->days() <= 92 => self::Weekly,
                default => self::Monthly,
            },
        };
    }
}
