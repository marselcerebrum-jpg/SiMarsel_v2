<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained();
            $table->string('sync_type', 30);
            $table->string('trigger_type', 30);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('status', 30);
            $table->integer('records_fetched')->nullable();
            $table->integer('records_created')->nullable();
            $table->integer('records_updated')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
