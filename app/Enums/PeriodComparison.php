<?php

namespace App\Enums;

/**
 * How the comparison period is chosen. Every option leaves the active period unchanged.
 */
enum PeriodComparison: string
{
    case Auto = 'auto';
    case PreviousPeriod = 'previous_period';
    case SamePeriodLastMonth = 'same_period_last_month';
    case SamePeriodLastYear = 'same_period_last_year';
    case Custom = 'custom';
}
