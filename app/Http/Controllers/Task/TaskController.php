<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\AssignTaskRequest;
use App\Http\Requests\Task\ChangeTaskStatusRequest;
use App\Http\Requests\Task\StoreTaskEvidenceRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\TaskListRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskEvidenceResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Services\TaskEvidenceService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function evidences(Task $task, TaskEvidenceService $service): AnonymousResourceCollection
    {
        return TaskEvidenceResource::collection($service->list($task));
    }

    public function storeEvidence(StoreTaskEvidenceRequest $request, Task $task, TaskEvidenceService $service): JsonResponse
    {
        $evidence = $service->store($task, $request->user(), $request->validated());

        return TaskEvidenceResource::make($evidence)
            ->additional(['message' => 'Bukti tugas berhasil dikirim.'])
            ->response()
            ->setStatusCode(201);
    }

    public function downloadEvidence(Task $task, TaskEvidence $evidence, TaskEvidenceService $service): StreamedResponse
    {
        return $service->download($task, $evidence);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskDetailResource
    {
        $task = $this->taskService->update($request->user(), $task, $request->validated());

        return TaskDetailResource::make($task)->additional(['message' => 'Tugas berhasil diperbarui.']);
    }

    public function changeStatus(ChangeTaskStatusRequest $request, Task $task): TaskDetailResource
    {
        $task = $this->taskService->changeStatus($request->user(), $task, $request->validated());

        return TaskDetailResource::make($task)->additional(['message' => 'Status tugas berhasil diperbarui.']);
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
