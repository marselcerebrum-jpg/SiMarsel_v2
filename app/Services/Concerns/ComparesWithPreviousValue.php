<?php

namespace App\Services\Concerns;

trait ComparesWithPreviousValue
{
    public const TREND_UP = 'up';

    public const TREND_DOWN = 'down';

    public const TREND_SAME = 'same';

    /**
     * Change from the previous value to the current one; null values count as 0.
     * The percentage is null when the previous value is 0 or null.
     *
     * @return array{change_percentage: float|null, trend: string}
     */
    protected function changeFromPrevious(int|float|null $current, int|float|null $previous): array
    {
        $currentValue = $current ?? 0;
        $previousValue = $previous ?? 0;

        return [
            'change_percentage' => $previousValue == 0
                ? null
                : round(($currentValue - $previousValue) / $previousValue * 100, 2),
            'trend' => match ($currentValue <=> $previousValue) {
                1 => self::TREND_UP,
                -1 => self::TREND_DOWN,
                default => self::TREND_SAME,
            },
        ];
    }
}
