<?php

namespace App\Support;

/**
 * The product table's local controls. They only select and order rows of the dataset; they never change
 * the main filter or the contribution divisor.
 */
final class ProductPerformanceQuery
{
    public const DEFAULT_PER_PAGE = 10;

    public function __construct(
        public readonly string $search = '',
        public readonly string $sort = 'revenue',
        public readonly string $direction = 'desc',
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search === '' ? null : $this->search,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }
}
