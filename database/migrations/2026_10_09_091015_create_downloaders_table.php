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
        Schema::create('downloaders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained();
            $table->date('date');
            $table->unsignedBigInteger('total');
            $table->timestamps();

            $table->unique(['platform_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('downloaders');
    }
};
