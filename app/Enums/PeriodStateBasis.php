<?php

namespace App\Enums;

/**
 * Which transaction state the aggregation must read for a period:
 * the state recorded at the cutoff (equivalent hour) or the latest stored state (full period).
 */
enum PeriodStateBasis: string
{
    case AtCutoff = 'at_cutoff';
    case Latest = 'latest';
}
