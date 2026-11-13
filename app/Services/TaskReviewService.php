<?php

namespace App\Services;

use App\Enums\TaskReviewDecision;
use App\Enums\TaskStatus;
use App\Exceptions\TaskVersionConflictException;
use App\Models\Account;
use App\Models\Task;
use App\Models\TaskReview;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskReviewService
{
    public function __construct(private TaskService $taskService) {}

    /**
     * Every review of the task, newest first, with who decided it.
     *
     * @return Collection<int, TaskReview>
     */
    public function list(Task $task): Collection
    {
        return $task->reviews()->with('reviewer')->get();
    }

    /**
     * Record the QA decision of an Admin or the division's Manager on a task in On Review: Approve moves it to Done
     * and stores when it was completed, Request Revision sends it back to On Process with the reason for its PIC.
     * The decision is kept as a review and in the history, so earlier review cycles stay on record.
     *
     * @param  array{version: int|string, decision: string, note?: ?string}  $data
     *
     * @throws TaskVersionConflictException When the task was saved by someone else after the reviewer loaded it.
     * @throws ValidationException When the task does not require QA or is not in On Review.
     */
    public function decide(Account $reviewer, Task $task, array $data): Task
    {
        $baseVersion = (int) $data['version'];
        $decision = TaskReviewDecision::from($data['decision']);

        DB::transaction(function () use ($reviewer, $task, $data, $baseVersion, $decision) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $baseVersion) {
                throw new TaskVersionConflictException($locked->version);
            }

            if (! $locked->requires_review) {
                throw ValidationException::withMessages(['decision' => 'Tugas ini tidak memerlukan QA.']);
            }

            if ($locked->status !== TaskStatus::OnReview) {
                throw ValidationException::withMessages([
                    'decision' => "Keputusan review hanya dapat diberikan pada tugas berstatus On Review, bukan {$locked->status->label()}.",
                ]);
            }

            $note = $data['note'] ?? null;
            $review = $locked->reviews()->create([
                'reviewer_id' => $reviewer->id,
                'cycle' => $locked->reviews()->count() + 1,
                'decision' => $decision,
                'note' => $note,
            ]);

            $status = $decision->nextStatus();
            $changes = ['status' => ['old' => $locked->status->value, 'new' => $status->value]];

            $locked->status = $status;
            $locked->completed_at = $status === TaskStatus::Done ? now() : null;
            $locked->version = $baseVersion + 1;
            $locked->save();

            $locked->activities()->create([
                'account_id' => $reviewer->id,
                'action' => $decision->activityAction(),
                'changes' => [
                    ...$changes,
                    'review' => ['old' => null, 'new' => ['id' => $review->id, 'cycle' => $review->cycle, 'note' => $note]],
                ],
            ]);
        });

        return $this->taskService->detail($task->refresh());
    }
}
