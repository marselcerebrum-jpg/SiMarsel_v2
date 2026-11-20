<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Whether a reported value is known: complete data, data up to a partial cutoff, or not known yet.
 */
enum DataAvailability: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';

    /**
     * @param  CarbonImmutable|null  $completeUntil  The exclusive end of complete data; null when unknown.
     * @param  CarbonImmutable  $periodEnd  The exclusive end of the period.
     */
    public static function forCoverage(?CarbonImmutable $completeUntil, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): self
    {
        return match (true) {
            $completeUntil === null || $completeUntil->lte($periodStart) => self::Unavailable,
            $completeUntil->gte($periodEnd) => self::Complete,
            default => self::Partial,
        };
    }
}
