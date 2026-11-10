<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();
            // The division that owns or runs the agenda; it does not decide who may see it.
            $table->foreignId('owner_division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // Shared agendas are visible to every role; the others only to Admin and their target divisions.
            $table->boolean('is_shared')->default(true);
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        // The divisions an internal agenda is meant for.
        Schema::create('schedule_event_divisions', function (Blueprint $table) {
            $table->foreignId('schedule_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();

            $table->primary(['schedule_event_id', 'division_id']);
            $table->index('division_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_event_divisions');
        Schema::dropIfExists('schedule_events');
    }
};
