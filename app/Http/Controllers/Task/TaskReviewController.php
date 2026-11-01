<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\DecideTaskReviewRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskReviewResource;
use App\Models\Task;
use App\Services\TaskReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class TaskReviewController extends Controller
{
    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        return TaskReviewResource::collection($task->reviews()->with('reviewer')->get());
    }

    public function start(Task $task, TaskReviewService $service): TaskDetailResource
    {
        return TaskDetailResource::make($service->start($task))
            ->additional(['message' => 'Pemeriksaan QA dimulai.']);
    }

    public function decide(DecideTaskReviewRequest $request, Task $task, TaskReviewService $service): TaskDetailResource
    {
        $task = $service->decide($task, $request->user(), $request->validated());
        $message = $request->validated('decision') === 'approve'
            ? 'Tugas berhasil disetujui.'
            : 'Revisi tugas berhasil diminta.';

        return TaskDetailResource::make($task)->additional(['message' => $message]);
    }
}

