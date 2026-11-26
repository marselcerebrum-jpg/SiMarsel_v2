<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('key', 20);
            $table->string('label', 255);
            $table->string('semantic', 50)->default('');
            $table->integer('sort')->default(0);
            $table->boolean('archived')->default(false);
            $table->string('category', 255)->nullable();
            $table->timestamps();

            $table->index(['key', 'archived', 'sort'], 'options_key_archived_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
