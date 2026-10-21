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
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained();
            $table->string('code');
            $table->foreignId('funnel_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('promo_sources')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['platform_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
