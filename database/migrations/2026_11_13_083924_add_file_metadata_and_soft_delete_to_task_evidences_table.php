<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * File metadata is nullable because existing evidence and links do not have it.
     */
    public function up(): void
    {
        Schema::table('task_evidences', function (Blueprint $table) {
            $table->string('mime_type', 100)->nullable()->after('file_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->softDeletes()->after('updated_at');
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_evidences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
            $table->dropColumn(['mime_type', 'file_size']);
        });
    }
};
