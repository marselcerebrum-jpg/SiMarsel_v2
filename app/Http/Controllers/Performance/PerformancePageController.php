<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use Illuminate\View\View;

class PerformancePageController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.performance.index', [
            'platforms' => Platform::orderBy('name')->get(['slug', 'name']),
        ]);
    }
}
