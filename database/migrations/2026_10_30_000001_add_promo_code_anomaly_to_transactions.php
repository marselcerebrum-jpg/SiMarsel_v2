<?php

use App\Support\PromoCodePayload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('promo_code_anomaly')->default(false);
        });

        // Existing invalid raw payloads must not be counted as "without promo" after deployment.
        // Only mark them; never reclassify historical snapshots or modify source timestamps.
        DB::table('transactions')->select('id', 'promo_code')->whereNotNull('promo_code')
            ->chunkById(500, function ($transactions) {
                $ids = $transactions->filter(fn ($transaction) => PromoCodePayload::anomalyReason(
                    PromoCodePayload::codesByPosition(json_decode($transaction->promo_code, true)),
                ) !== null)->pluck('id')->all();

                if ($ids !== []) {
                    DB::table('transactions')->whereIn('id', $ids)->update(['promo_code_anomaly' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('promo_code_anomaly');
        });
    }
};
