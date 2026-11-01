<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Task;
use App\Models\TaskEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TaskEvidenceService
{
    public function list(Task $task)
    {
        return $task->evidences()->with('submitter')->get();
    }

    public function store(Task $task, Account $submitter, array $data): TaskEvidence
    {
        abort_unless(in_array($task->status, [TaskStatus::Todo, TaskStatus::InProgress], true), 422, 'Evidence hanya dapat dikirim saat tugas berstatus To Do atau On Process.');

        $file = $data['file'] ?? null;
        $path = $file instanceof UploadedFile
            ? $file->store("task-evidence/{$task->id}", 'local')
            : null;

        if ($file instanceof UploadedFile && $path === false) {
            abort(500, 'File bukti gagal disimpan.');
        }

        try {
            return DB::transaction(fn () => $task->evidences()->create([
                'submitter_id' => $submitter->id,
                'type' => $file instanceof UploadedFile ? TaskEvidence::FILE : TaskEvidence::LINK,
                'file_path' => $path,
                'file_name' => $file instanceof UploadedFile ? $file->getClientOriginalName() : null,
                'url' => $data['url'] ?? null,
                'note' => $data['note'] ?? null,
            ])->load('submitter'));
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    public function submit(Task $task): Task
    {
        if ($task->status !== TaskStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Tugas hanya dapat disubmit dari status On Process.']);
        }

        if ($task->evidence_required && ! $task->evidences()->exists()) {
            throw ValidationException::withMessages(['evidence' => 'Bukti wajib dilampirkan sebelum tugas disubmit.']);
        }

        $task->status = $task->requires_review ? TaskStatus::ReadyForQa : TaskStatus::Done;
        $task->completed_at = $task->requires_review ? null : now();
        $task->save();

        return $task->load(['category', 'division', 'platform', 'pic', 'creator'])
            ->loadCount('evidences');
    }

    public function download(TaskEvidence $evidence)
    {
        abort_unless($evidence->type === TaskEvidence::FILE && $evidence->file_path, 404);
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);

        return Storage::disk('local')->download($evidence->file_path, $evidence->file_name);
    }
}
