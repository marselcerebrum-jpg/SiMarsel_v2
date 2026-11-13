<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared agendas that stand on their own, visible to every active account. Task start dates and deadlines are
     * read from tasks by the Calendar and are not copied here.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_id')->constrained('accounts');
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('start_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
