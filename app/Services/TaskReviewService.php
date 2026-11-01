<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Task;
use App\Models\TaskReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskReviewService
{
    public function start(Task $task): Task
    {
        return DB::transaction(function () use ($task) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! $task->requires_review || $task->status !== TaskStatus::ReadyForQa) {
                throw ValidationException::withMessages(['status' => 'Pemeriksaan hanya dapat dimulai untuk tugas wajib QA berstatus Ready for QA.']);
            }
            $task->status = TaskStatus::OnQa;
            $task->save();
            return $this->withReviewData($task);
        });
    }

    public function decide(Task $task, Account $reviewer, array $data): Task
    {
        return DB::transaction(function () use ($task, $reviewer, $data) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! $task->requires_review || $task->status !== TaskStatus::OnQa) {
                throw ValidationException::withMessages(['status' => 'Keputusan hanya dapat diberikan untuk tugas wajib QA berstatus On QA.']);
            }

            $approved = $data['decision'] === 'approve';
            $note = trim((string) ($data['note'] ?? ''));
            if (! $approved && $note === '') {
                throw ValidationException::withMessages(['note' => 'Catatan alasan wajib diisi saat meminta revisi.']);
            }

            $task->reviews()->create([
                'reviewer_id' => $reviewer->id,
                'decision' => $approved ? TaskReview::APPROVED : TaskReview::REVISION_REQUESTED,
                'note' => $note !== '' ? $note : null,
            ]);
            $task->status = $approved ? TaskStatus::Done : TaskStatus::InProgress;
            $task->completed_at = $approved ? now() : null;
            $task->save();
            return $this->withReviewData($task);
        });
    }

    private function withReviewData(Task $task): Task
    {
        return $task->load(['category', 'division', 'platform', 'pic', 'creator', 'evidences.submitter', 'reviews.reviewer'])
            ->loadCount('evidences');
    }
}
