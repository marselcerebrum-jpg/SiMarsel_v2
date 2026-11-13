<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collaborating divisions of a task. tasks.division_id stays the owning division and is not repeated here.
     */
    public function up(): void
    {
        Schema::create('task_divisions', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['task_id', 'division_id']);
            $table->index('division_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_divisions');
    }
};
