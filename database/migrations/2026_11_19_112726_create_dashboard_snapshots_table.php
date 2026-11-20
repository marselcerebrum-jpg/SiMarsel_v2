<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-15-BE-03: values recorded at a cutoff for equivalent-hour comparisons, with the completeness
 * flag of that platform and dataset. downloaders keeps one daily total per (platform_id, date); a
 * snapshot is a separate copy that later changes to transactions or downloaders never rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained();
            $table->string('dataset', 30);
            $table->date('date_from');
            $table->date('date_to');
            $table->dateTime('cutoff_at');
            $table->boolean('is_complete')->default(false);
            $table->json('payload');
            $table->dateTime('recorded_at');

            $table->unique(['platform_id', 'dataset', 'date_from', 'date_to', 'cutoff_at'], 'dashboard_snapshots_period_cutoff_unique');
            $table->index(['dataset', 'date_from', 'date_to', 'is_complete', 'cutoff_at'], 'dashboard_snapshots_complete_cutoff_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
    }
};
