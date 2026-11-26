<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_apps', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('accounts')->restrictOnDelete();
            $table->string('app', 64);

            $table->primary(['user_id', 'app']);
            $table->index('app', 'user_apps_app_index');
            $table->foreign('app')->references('id')->on('options')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_apps');
    }
};
