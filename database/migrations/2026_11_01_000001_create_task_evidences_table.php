<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitter_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('type', 10);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->text('url')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_evidences');
    }
};
