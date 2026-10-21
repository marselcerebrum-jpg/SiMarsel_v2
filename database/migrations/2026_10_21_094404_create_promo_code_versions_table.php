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
        Schema::create('promo_code_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained();
            $table->string('code');
            $table->foreignId('funnel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('funnel_name_snapshot', 100)->nullable();
            $table->foreignId('source_id')->nullable()->constrained('promo_sources')->nullOnDelete();
            $table->string('source_name_snapshot', 100)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();

            $table->unique(['promo_code_id', 'effective_from']);
            $table->index(['platform_id', 'code', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_code_versions');
    }
};
