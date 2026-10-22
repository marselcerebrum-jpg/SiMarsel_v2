<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->foreignId('division_id')->constrained();
            $table->foreignId('pic_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('priority', 10)->default(TaskPriority::Medium->value);
            $table->string('status', 20)->default(TaskStatus::Backlog->value);
            $table->date('start_date')->nullable();
            $table->date('deadline')->nullable();
            $table->boolean('requires_review')->default(false);
            $table->boolean('evidence_required')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['division_id', 'status']);
            $table->index(['pic_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
