<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\TaskMonitoringRequest;
use App\Services\TaskMonitoringService;
use Illuminate\Http\JsonResponse;

class TaskMonitoringController extends Controller
{
    public function __construct(private TaskMonitoringService $monitoring) {}

    public function __invoke(TaskMonitoringRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->monitoring->summarize($request->filters())]);
    }
}
