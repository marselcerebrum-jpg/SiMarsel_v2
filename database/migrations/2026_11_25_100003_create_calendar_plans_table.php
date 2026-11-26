<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_plans', function (Blueprint $table) {
            $table->string('app', 64);
            $table->string('type', 20);
            $table->date('date');
            $table->unsignedInteger('amount')->nullable();
            $table->string('color', 30)->nullable();
            $table->timestamps();

            $table->primary(['app', 'type', 'date']);
            $table->index('date', 'calendar_plans_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_plans');
    }
};
