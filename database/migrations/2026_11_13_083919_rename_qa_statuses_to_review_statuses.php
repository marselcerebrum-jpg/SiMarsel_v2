<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The QA steps are renamed to Ready Review and On Review (TASK-17). Task history is rewritten with the same
     * mapping so old and new entries read the same way; only exact values under the "status" change are replaced,
     * so a title that happens to read "on_qa" is left alone, and nothing is removed.
     */
    private const RENAMES = [
        'ready_for_qa' => 'ready_review',
        'on_qa' => 'on_review',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    /**
     * @param  array<string, string>  $map
     */
    private function rename(array $map): void
    {
        DB::transaction(function () use ($map) {
            foreach ($map as $from => $to) {
                DB::table('tasks')->where('status', $from)->update(['status' => $to]);
            }

            // Task history is append-only for the application; this data migration is the one sanctioned rewrite.
            DB::table('task_activities')
                ->whereNotNull('changes')
                ->where(function ($query) use ($map) {
                    foreach (array_keys($map) as $from) {
                        $query->orWhere('changes', 'like', '%"'.$from.'"%');
                    }
                })
                ->orderBy('id')
                ->chunkById(500, function ($activities) use ($map) {
                    foreach ($activities as $activity) {
                        $changes = json_decode($activity->changes, true);

                        if (! is_array($changes) || ! isset($changes['status'])) {
                            continue;
                        }

                        $renamed = $changes;
                        $renamed['status'] = $this->replaceValues($changes['status'], $map);

                        if ($renamed !== $changes) {
                            DB::table('task_activities')->where('id', $activity->id)->update(['changes' => json_encode($renamed)]);
                        }
                    }
                });
        });
    }

    /**
     * Replace string values that exactly match an old status, at any depth.
     *
     * @param  array<string, string>  $map
     */
    private function replaceValues(mixed $value, array $map): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->replaceValues($item, $map), $value);
        }

        return is_string($value) && isset($map[$value]) ? $map[$value] : $value;
    }
};
