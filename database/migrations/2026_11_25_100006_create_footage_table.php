<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('footage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('app', 64);
            $table->string('type', 20);
            $table->string('kind', 10);
            $table->text('title');
            $table->text('url')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('app')->references('id')->on('options')->restrictOnDelete();
            $table->index(['app', 'type'], 'footage_scope_index');
            $table->index('content_id', 'footage_content_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footage');
    }
};
