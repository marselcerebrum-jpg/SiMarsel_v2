<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceFilterRequest;
use App\Models\Platform;
use App\Models\Transaction;
use Illuminate\View\View;

class PerformancePageController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.performance.index', [
            'platforms' => Platform::orderBy('name')->get(['slug', 'name']),
            'years' => $this->years(),
        ]);
    }

    /**
     * Years the filter offers, newest first: from the first transaction, and at least the last
     * MAX_YEARS years so a multi-year comparison is always possible, up to this year.
     *
     * @return list<int>
     */
    private function years(): array
    {
        $current = now()->year;
        $first = Transaction::min('paid_date');
        $from = min($current - PerformanceFilterRequest::MAX_YEARS + 1, $first ? (int) substr((string) $first, 0, 4) : $current);

        return range($current, max($from, PerformanceFilterRequest::MIN_YEAR));
    }
}
