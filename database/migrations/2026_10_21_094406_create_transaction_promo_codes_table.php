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
        Schema::create('transaction_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promo_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('raw_code');
            $table->unsignedTinyInteger('code_position');
            $table->foreignId('platform_id_snapshot')->constrained('platforms');
            $table->string('code_snapshot');
            $table->foreignId('funnel_id_snapshot')->nullable()->constrained('funnels')->nullOnDelete();
            $table->string('funnel_name_snapshot', 100)->nullable();
            $table->foreignId('source_id_snapshot')->nullable()->constrained('promo_sources')->nullOnDelete();
            $table->string('source_name_snapshot', 100)->nullable();
            $table->timestamps();

            $table->unique(['transaction_id', 'code_position']);
            $table->index(['platform_id_snapshot', 'raw_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_promo_codes');
    }
};
