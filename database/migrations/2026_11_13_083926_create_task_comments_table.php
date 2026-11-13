<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comments and replies. A reply's parent must belong to the same task, which the Backend checks.
     */
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('parent_comment_id')->nullable()->constrained('task_comments')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
