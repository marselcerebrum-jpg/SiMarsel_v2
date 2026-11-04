<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskEvidenceService
{
    /**
     * Only file evidence of this task has something to download; evidence of another task, a link, or a
     * missing stored file is treated as not found.
     */
    public function download(Task $task, TaskEvidence $evidence): StreamedResponse
    {
        abort_unless($evidence->task_id === $task->id, 404);
        abort_unless($evidence->type === TaskEvidence::FILE && $evidence->file_path, 404);
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);

        return Storage::disk('local')->download($evidence->file_path, $evidence->file_name);
    }
}
