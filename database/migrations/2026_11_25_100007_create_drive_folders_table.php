<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->string('app', 64);
            $table->string('type', 20);
            $table->text('url');
            $table->timestamps();

            $table->primary(['app', 'type']);
            $table->foreign('app')->references('id')->on('options')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_folders');
    }
};
