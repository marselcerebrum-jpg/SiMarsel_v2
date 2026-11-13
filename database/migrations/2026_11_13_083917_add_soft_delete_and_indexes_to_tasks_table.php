<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deleted task leaves the active lists but keeps its history, evidence, reviews, and comments.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->softDeletes()->after('version');
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('accounts')->nullOnDelete();
            $table->index(['status', 'deadline']);
            $table->index('start_date');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status', 'deadline']);
            $table->dropIndex(['start_date']);
            $table->dropIndex(['deleted_at']);
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
