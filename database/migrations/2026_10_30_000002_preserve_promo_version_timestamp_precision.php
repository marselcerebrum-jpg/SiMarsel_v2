<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_code_versions', function (Blueprint $table) {
            $table->timestamp('effective_from', 6)->change();
            $table->timestamp('effective_until', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Truncation could both lose audit precision and violate the existing unique constraint.
        DB::table('promo_code_versions')->select('id', 'effective_from', 'effective_until')
            ->chunkById(500, function ($versions) {
                foreach ($versions as $version) {
                    foreach ([$version->effective_from, $version->effective_until] as $time) {
                        if ($time !== null && CarbonImmutable::parse($time)->micro !== 0) {
                            throw new LogicException('Cannot roll back promo version precision while fractional timestamps exist. Preserve the audit history.');
                        }
                    }
                }
            });

        Schema::table('promo_code_versions', function (Blueprint $table) {
            $table->timestamp('effective_from')->change();
            $table->timestamp('effective_until')->nullable()->change();
        });
    }
};
