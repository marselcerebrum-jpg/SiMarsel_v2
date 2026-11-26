<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskEvidence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TaskEvidenceService
{
    /**
     * Statuses in which the PIC is still working on the task and may add or delete their evidence without moving it.
     * A revision sends the task back to Back Log, so new evidence can be sent right away; earlier evidence is never
     * replaced.
     */
    public const SUBMITTABLE_STATUSES = [TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::InProgress];

    /**
     * Stops the browser from guessing another type than the one served, so a file cannot be run as a script.
     */
    private const SAFE_HEADERS = ['X-Content-Type-Options' => 'nosniff'];

    private const DISK = 'local';

    /**
     * Every evidence of the task, newest first, with who sent it.
     *
     * @return Collection<int, TaskEvidence>
     */
    public function list(Task $task): Collection
    {
        return $task->evidences()->with('submitter')->get();
    }

    /**
     * Store a file or link as evidence of the task, sent by its PIC, and record it in the task's history.
     * The PIC and status are checked again on the locked row, so evidence cannot slip in while the task is being
     * reassigned or handed to review. A stored file is removed again when saving the record fails, so no orphan file
     * is left behind.
     *
     * @param  array{file?: UploadedFile, url?: ?string, note?: ?string}  $data
     *
     * @throws AuthorizationException When the submitter is no longer the task's PIC.
     * @throws ValidationException When the task is no longer in a status that accepts evidence.
     */
    public function store(Task $task, Account $submitter, array $data): TaskEvidence
    {
        $this->ensureSubmittable($task, $submitter);

        $file = $data['file'] ?? null;
        $path = $file instanceof UploadedFile ? $this->storeFile($task, $file) : null;

        try {
            return DB::transaction(function () use ($task, $submitter, $data, $file, $path) {
                $this->ensureSubmittable(Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail(), $submitter);

                $evidence = $task->evidences()->create([
                    'submitter_id' => $submitter->id,
                    'type' => $path ? TaskEvidence::FILE : TaskEvidence::LINK,
                    'file_path' => $path,
                    'file_name' => $path ? $file->getClientOriginalName() : null,
                    // The type detected from the content, never the one the browser claimed.
                    'mime_type' => $path ? $file->getMimeType() : null,
                    'file_size' => $path ? $file->getSize() : null,
                    'url' => $path ? null : $data['url'],
                    'note' => $data['note'] ?? null,
                ]);

                $task->activities()->create([
                    'account_id' => $submitter->id,
                    'action' => TaskActivity::EVIDENCE_SUBMITTED,
                    'changes' => ['evidence' => [
                        'old' => null,
                        'new' => ['id' => $evidence->id, 'type' => $evidence->type, 'name' => $evidence->file_name ?? $evidence->url],
                    ]],
                ]);

                return $evidence->load('submitter');
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Delete evidence the PIC uploaded (soft delete) and record it in the task's history. The stored file is kept so
     * the deletion can be traced; the evidence leaves every list and can no longer be downloaded. The task itself, its
     * status, and other evidence are not touched. The task and the evidence are read again under lock, so evidence
     * cannot be deleted while the task is being reassigned or handed to review, and a request that lost the race to delete
     * the same evidence stops without adding to the history.
     *
     * @throws ModelNotFoundException When the evidence has already been deleted.
     * @throws AuthorizationException When the account is not the PIC who uploaded it.
     * @throws ValidationException When the task is no longer in a status in which evidence may change.
     */
    public function delete(Task $task, TaskEvidence $evidence, Account $actor): void
    {
        abort_unless($evidence->task_id === $task->id, 404);

        DB::transaction(function () use ($task, $evidence, $actor) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $current = $locked->evidences()->whereKey($evidence->id)->lockForUpdate()->firstOrFail();

            if ($current->submitter_id !== $actor->id || ! $locked->assignedTo($actor)) {
                throw new AuthorizationException;
            }

            if (! in_array($locked->status, self::SUBMITTABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'evidence' => "Evidence hanya dapat dihapus saat tugas berstatus Back Log, To Do, atau On Process, bukan {$locked->status->label()}.",
                ]);
            }

            $current->deleted_by = $actor->id;
            $current->save();
            $current->delete();

            $locked->activities()->create([
                'account_id' => $actor->id,
                'action' => TaskActivity::EVIDENCE_DELETED,
                'changes' => ['evidence' => [
                    'old' => ['id' => $current->id, 'type' => $current->type, 'name' => $current->file_name ?? $current->url],
                    'new' => null,
                ]],
            ]);
        });
    }

    /**
     * Only file evidence of this task has something to download; evidence of another task, a link, or a
     * missing stored file is treated as not found.
     */
    public function download(Task $task, TaskEvidence $evidence): StreamedResponse
    {
        $this->ensureStoredFile($task, $evidence);

        return Storage::disk(self::DISK)->download($evidence->file_path, $evidence->file_name, self::SAFE_HEADERS);
    }

    /**
     * Show a JPG, PNG, or WEBP image inline so it can be previewed. Every other evidence is treated as not found;
     * documents are opened through download().
     */
    public function preview(Task $task, TaskEvidence $evidence): StreamedResponse
    {
        $this->ensureStoredFile($task, $evidence);
        abort_unless($evidence->previewable(), 404);

        return Storage::disk(self::DISK)->response(
            $evidence->file_path,
            $evidence->file_name,
            ['Content-Type' => $evidence->effectiveMimeType(), ...self::SAFE_HEADERS],
            'inline',
        );
    }

    private function ensureStoredFile(Task $task, TaskEvidence $evidence): void
    {
        abort_unless($evidence->task_id === $task->id, 404);
        abort_unless($evidence->type === TaskEvidence::FILE && $evidence->file_path, 404);
        abort_unless(Storage::disk(self::DISK)->exists($evidence->file_path), 404);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function ensureSubmittable(Task $task, Account $submitter): void
    {
        if (! $task->assignedTo($submitter)) {
            throw new AuthorizationException;
        }

        if (! in_array($task->status, self::SUBMITTABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'evidence' => "Evidence hanya dapat dikirim saat tugas berstatus Back Log, To Do, atau On Process, bukan {$task->status->label()}.",
            ]);
        }
    }

    /**
     * Files are kept on the private disk per task and are only served through download().
     */
    private function storeFile(Task $task, UploadedFile $file): string
    {
        $path = $file->store("task-evidences/{$task->id}", self::DISK);

        if ($path === false) {
            throw new RuntimeException("Evidence file for task {$task->id} could not be stored.");
        }

        return $path;
    }
}
