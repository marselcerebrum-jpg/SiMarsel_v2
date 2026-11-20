<?php

namespace App\Enums;

/**
 * The stored data a dashboard_snapshots row and its completeness flag describe.
 */
enum DashboardDataset: string
{
    /** Checkout metrics by created date and payment metrics by paid date. */
    case Transactions = 'transactions';

    /** Cumulative downloader totals per covered date. */
    case Downloaders = 'downloaders';
}
