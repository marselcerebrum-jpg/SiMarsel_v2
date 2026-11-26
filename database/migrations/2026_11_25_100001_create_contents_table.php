<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->text('title');
            $table->string('app', 64);
            $table->string('type', 20);
            $table->unsignedInteger('type_no')->nullable();
            $table->date('created_date');
            $table->date('upload_date')->nullable();
            $table->string('script_status', 64);
            $table->string('talent_status', 64)->nullable();
            $table->string('talent_name', 64)->nullable();
            $table->string('creative_status', 64);
            $table->string('qc_status', 64);
            $table->string('priority', 64)->default('priority:1');
            $table->text('link')->nullable();
            $table->text('notes')->nullable();
            $table->longText('sheet')->nullable();
            $table->foreignId('marketing_user_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('editor_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->dateTime('script_ready_at')->nullable();
            $table->dateTime('talent_done_at')->nullable();
            $table->dateTime('creative_done_at')->nullable();
            $table->dateTime('link_at')->nullable();
            $table->dateTime('qc_at')->nullable();
            $table->text('published_url')->nullable();
            $table->date('published_date')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->foreign('app')->references('id')->on('options')->restrictOnDelete();
            $table->foreign('script_status')->references('id')->on('options')->restrictOnDelete();
            $table->foreign('talent_status')->references('id')->on('options')->restrictOnDelete();
            $table->foreign('talent_name')->references('id')->on('options')->restrictOnDelete();
            $table->foreign('creative_status')->references('id')->on('options')->restrictOnDelete();
            $table->foreign('qc_status')->references('id')->on('options')->restrictOnDelete();

            $table->unique(['type', 'type_no'], 'contents_type_no_unique');
            $table->index('upload_date', 'contents_upload_date_index');
            $table->index('created_date', 'contents_created_date_index');
            $table->index(['app', 'type', 'archived'], 'contents_app_type_archived_index');
            $table->index(['editor_account_id', 'archived'], 'contents_editor_archived_index');
            $table->index(['creative_status', 'archived'], 'contents_creative_status_archived_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
