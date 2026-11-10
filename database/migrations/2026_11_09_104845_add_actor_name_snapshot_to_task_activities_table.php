<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the actor's name on each history entry, so it stays readable after the account is deleted.
     * Existing entries whose account still exists are filled from that account.
     */
    public function up(): void
    {
        Schema::table('task_activities', function (Blueprint $table) {
            $table->string('actor_name_snapshot')->nullable()->after('account_id');
        });

        DB::table('task_activities')
            ->whereNull('actor_name_snapshot')
            ->whereNotNull('account_id')
            ->update([
                'actor_name_snapshot' => DB::raw('(select fullname from accounts where accounts.id = task_activities.account_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_activities', function (Blueprint $table) {
            $table->dropColumn('actor_name_snapshot');
        });
    }
};
