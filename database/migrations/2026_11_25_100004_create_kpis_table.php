<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('accounts')->restrictOnDelete();
            $table->string('app', 64);
            $table->string('type', 20);
            $table->date('work_date');
            $table->date('upload_date');
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->unique(['user_id', 'app', 'type', 'work_date'], 'kpis_user_app_type_work_date_unique');
            $table->index(['app', 'type', 'work_date'], 'kpis_app_type_work_date_index');
            $table->index('upload_date', 'kpis_upload_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpis');
    }
};
