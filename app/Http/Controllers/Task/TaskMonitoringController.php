<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\TaskMonitoringRequest;
use App\Http\Resources\TaskMonitoringResource;
use App\Services\TaskMonitoringService;

class TaskMonitoringController extends Controller
{
    public function __construct(private TaskMonitoringService $monitoringService) {}

    public function __invoke(TaskMonitoringRequest $request): TaskMonitoringResource
    {
        return TaskMonitoringResource::make(
            $this->monitoringService->summarize($request->user(), $request->scope(), $request->filters()),
        );
    }
}
