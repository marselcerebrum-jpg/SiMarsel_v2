<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('field', 255);
            $table->longText('from_value')->nullable();
            $table->longText('to_value')->nullable();
            $table->dateTime('at');

            $table->index(['content_id', 'at'], 'events_content_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
