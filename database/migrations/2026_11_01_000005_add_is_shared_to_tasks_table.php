<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->after('deadline');
            $table->index(['is_shared', 'start_date', 'deadline']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['is_shared', 'start_date', 'deadline']);
            $table->dropColumn('is_shared');
        });
    }
};
