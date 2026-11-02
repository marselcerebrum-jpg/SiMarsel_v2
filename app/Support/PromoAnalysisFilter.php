<?php

namespace App\Support;

/**
 * The active Analisis Promo filter shared by every promo analysis component: the period and platforms
 * from the performance filter, plus payment methods and a promo code search.
 */
final class PromoAnalysisFilter
{
    /**
     * @param  list<string>  $paymentMethods  As shown in the filter options; empty means every payment method.
     */
    public function __construct(
        public readonly PerformanceFilter $period,
        public readonly array $paymentMethods = [],
        public readonly ?string $search = null,
    ) {}

    /**
     * The same filter applied to the comparison period.
     */
    public function previous(): self
    {
        return new self($this->period->previous(), $this->paymentMethods, $this->search);
    }

    /**
     * The same filter without the promo code search, for totals that compare against every sale.
     */
    public function withoutSearch(): self
    {
        return new self($this->period, $this->paymentMethods);
    }

    public function hasSearch(): bool
    {
        return $this->search !== null && trim($this->search) !== '';
    }

    /**
     * The active filter as returned in every promo analysis response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->period->toArray(),
            'payment_methods' => $this->paymentMethods,
            'search' => $this->hasSearch() ? trim($this->search) : null,
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, ranges: list<array{date_from: string, date_to: string}>}
     */
    public function previousToArray(): array
    {
        return $this->period->previousToArray();
    }
}
