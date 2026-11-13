<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task may have several active PICs. An assignment is active while unassigned_at is null; ending it keeps the row,
     * so replacing a PIC never removes the assignment history.
     *
     * active_flag is 1 for an active assignment and NULL otherwise. A unique index allows many NULLs, so the index on
     * (task_id, account_id, active_flag) stops the same account being an active PIC twice while keeping ended rows.
     */
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('unassigned_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('unassigned_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('active_flag')->nullable()->storedAs('CASE WHEN unassigned_at IS NULL THEN 1 END');
            $table->unique(['task_id', 'account_id', 'active_flag'], 'task_assignments_active_pic_unique');
            $table->index(['task_id', 'unassigned_at']);
            $table->index(['account_id', 'unassigned_at']);
        });

        $this->backfillFromPicId();
    }

    public function down(): void
    {
        // tasks.pic_id is left untouched by up(), so dropping the table loses no PIC data.
        Schema::dropIfExists('task_assignments');
    }

    /**
     * Copy every tasks.pic_id into an active assignment. Who assigned it and when come from the latest
     * assigned/reassigned history entry; a task without one falls back to its creator and creation time.
     */
    private function backfillFromPicId(): void
    {
        DB::table('tasks')
            ->whereNotNull('pic_id')
            ->orderBy('id')
            ->select(['id', 'pic_id', 'creator_id', 'created_at'])
            ->chunkById(500, function ($tasks) {
                // Ordered oldest first, so keyBy() keeps the latest entry of each task.
                $latestAssignments = DB::table('task_activities')
                    ->whereIn('task_id', $tasks->pluck('id'))
                    ->whereIn('action', ['assigned', 'reassigned'])
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get(['task_id', 'account_id', 'created_at'])
                    ->keyBy('task_id');

                $rows = $tasks->map(function ($task) use ($latestAssignments) {
                    $activity = $latestAssignments->get($task->id);

                    return [
                        'task_id' => $task->id,
                        'account_id' => $task->pic_id,
                        'assigned_by' => $activity ? $activity->account_id : $task->creator_id,
                        'assigned_at' => $activity->created_at ?? $task->created_at ?? now(),
                    ];
                });

                DB::table('task_assignments')->insertOrIgnore($rows->all());
            });
    }
};
