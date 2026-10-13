<?php

namespace App\Enums;

enum TrendMetric: string
{
    case Revenue = 'revenue';
    case Transactions = 'transactions';
    case Downloaders = 'downloaders';
    case Conversion = 'conversion';
    case NewUsers = 'new_users';
    case RepeatOrder = 'repeat_order';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue',
            self::Transactions => 'Transactions',
            self::Downloaders => 'Downloader',
            self::Conversion => 'Conversion',
            self::NewUsers => 'User Baru',
            self::RepeatOrder => 'Repeat Order',
        };
    }

    /**
     * Tells the frontend how to format the value: currency, count, or percent.
     */
    public function unit(): string
    {
        return match ($this) {
            self::Revenue => 'currency',
            self::Conversion => 'percent',
            default => 'count',
        };
    }
}
