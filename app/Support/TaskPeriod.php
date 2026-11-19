<?php

namespace App\Support;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The period chosen in the Tasklist filter, turned into one inclusive range of calendar days on start_date or
 * deadline. The list, its status counts, and the KPIs all read it from here, so they cannot interpret a mode differently.
 */
final readonly class TaskPeriod
{
    public const FIELD_START_DATE = 'start_date';

    public const FIELD_DEADLINE = 'deadline';

    public const FIELDS = [self::FIELD_START_DATE, self::FIELD_DEADLINE];

    public const MODE_DATE = 'date';

    public const MODE_MONTH = 'month';

    public const MODE_YEAR = 'year';

    public const MODE_RANGE = 'range';

    public const MODES = [self::MODE_DATE, self::MODE_MONTH, self::MODE_YEAR, self::MODE_RANGE];

    private function __construct(
        public string $field,
        public string $mode,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * The period described by validated input, or null when no period filter was asked for.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): ?self
    {
        $mode = $input['filter_mode'] ?? null;

        if ($mode === null) {
            return null;
        }

        [$from, $to] = match ($mode) {
            self::MODE_DATE => [self::day($input['date']), self::day($input['date'])],
            self::MODE_MONTH => [
                CarbonImmutable::create((int) $input['year'], (int) $input['month'], 1),
                CarbonImmutable::create((int) $input['year'], (int) $input['month'], 1)->endOfMonth(),
            ],
            self::MODE_YEAR => [
                CarbonImmutable::create((int) $input['year'], 1, 1),
                CarbonImmutable::create((int) $input['year'], 12, 31),
            ],
            self::MODE_RANGE => [self::day($input['start_date']), self::day($input['end_date'])],
        };

        return new self($input['date_field'], $mode, $from->startOfDay(), $to->startOfDay());
    }

    /**
     * Keep tasks whose date falls in the period. The upper bound is the start of the next day, so a stored time part
     * cannot hide the last day, no timezone conversion can shift a date, and the column's index stays usable. A task
     * without this date never matches.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function apply(Builder $query): Builder
    {
        $column = $query->qualifyColumn($this->field);

        return $query
            ->where($column, '>=', $this->from->toDateString())
            ->where($column, '<', $this->to->addDay()->toDateString());
    }

    public function label(): string
    {
        return $this->field === self::FIELD_START_DATE ? 'Tanggal mulai' : 'Deadline';
    }

    /**
     * @return array{field: string, mode: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'mode' => $this->mode,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }

    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date);
    }
}
