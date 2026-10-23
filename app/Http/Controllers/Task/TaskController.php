<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\AssignTaskRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\TaskListRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function __construct(private TaskService $taskService) {}

    public function meta(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->taskService->meta($request->user())]);
    }

    public function index(TaskListRequest $request): AnonymousResourceCollection
    {
        $result = $this->taskService->list(
            $request->user(),
            $request->filters(),
            $request->sort(),
            $request->direction(),
            $request->perPage(),
        );

        return TaskResource::collection($result['tasks'])->additional([
            'meta' => ['status_counts' => $result['status_counts']],
        ]);
    }

    public function createOptions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->taskService->createOptions($request->user())]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->taskService->create($request->user(), $request->validated());

        return TaskDetailResource::make($task)
            ->additional(['message' => 'Tugas berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Task $task): TaskDetailResource
    {
        return TaskDetailResource::make($this->taskService->detail($task));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskDetailResource
    {
        $task = $this->taskService->update($request->user(), $task, $request->validated());

        return TaskDetailResource::make($task)->additional(['message' => 'Tugas berhasil diperbarui.']);
    }

    public function assignmentOptions(Request $request, Task $task): JsonResponse
    {
        return response()->json(['data' => $this->taskService->assignmentOptions($request->user(), $task)]);
    }

    public function assign(AssignTaskRequest $request, Task $task): TaskDetailResource
    {
        $task = $this->taskService->assign($request->user(), $task, $request->validated());

        return TaskDetailResource::make($task)->additional(['message' => 'PIC berhasil diperbarui.']);
    }
}
