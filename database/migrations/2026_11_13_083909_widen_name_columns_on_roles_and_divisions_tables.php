<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the master names to the ERD To-Be lengths. Existing values and the unique index on divisions.name are kept.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('name', 50)->change();
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->string('name', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('name', 25)->change();
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->string('name', 25)->change();
        });
    }
};
