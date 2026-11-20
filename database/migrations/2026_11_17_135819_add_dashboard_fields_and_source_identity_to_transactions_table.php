<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-15-BE-02: store every Markaz status for the Dashboard contract and identify a Markaz
 * transaction per platform. Existing rows keep their local id and promo usages; source fields that
 * history never recorded stay NULL instead of being derived from local timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->date('paid_date')->nullable()->change();
            // Legacy: its mapping to the paying account is unverified. Existing values stay; new rows may leave it empty.
            $table->unsignedBigInteger('markaz_user_id')->nullable()->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('markaz_transaction_id')->nullable()->after('platform_id');
            $table->unsignedBigInteger('markaz_customer_id')->nullable()->after('markaz_user_id');
            $table->unsignedBigInteger('markaz_ref_id')->nullable()->after('trx_id');
            $table->dateTime('markaz_created_at')->nullable()->after('markaz_ref_id');
            $table->dateTime('markaz_paid_at')->nullable()->after('markaz_created_at');
            $table->unsignedTinyInteger('source_status')->nullable()->after('paid_time');
            $table->unsignedTinyInteger('effective_status')->nullable()->after('source_status');
            $table->boolean('is_claim_coupon')->default(false)->after('effective_status');
            $table->decimal('product_price', 15, 2)->nullable()->after('payment_method');
            $table->decimal('voucher_discount', 15, 2)->nullable()->after('product_price');
            $table->decimal('admin_fee', 15, 2)->nullable()->after('voucher_discount');
            $table->decimal('net_nominal', 15, 2)->nullable()->after('nominal');
            $table->string('customer_name')->nullable()->after('product_type');
            $table->string('customer_phone', 50)->nullable()->after('customer_email');
            $table->dateTime('synced_at')->nullable()->after('status_message');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // trx_id stays the display and matching number, but the same value may appear on other platforms.
            $table->dropUnique(['trx_id']);
            $table->index('trx_id');

            $table->unique(['platform_id', 'markaz_transaction_id']);
            $table->index(['platform_id', 'markaz_ref_id']);
            $table->index(['platform_id', 'markaz_created_at']);
            $table->index(['platform_id', 'paid_date']);
        });
    }

    public function down(): void
    {
        if (DB::table('transactions')->whereNull('paid_date')->exists()) {
            throw new LogicException('Cannot roll back while transactions without paid_date exist: paid_date would become required again. Keep the Pending history.');
        }

        if (DB::table('transactions')->whereNull('markaz_user_id')->exists()) {
            throw new LogicException('Cannot roll back while transactions without markaz_user_id exist: markaz_user_id would become required again.');
        }

        if (DB::table('transactions')->select('trx_id')->groupBy('trx_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Cannot roll back while the same trx_id is stored on several rows: trx_id would become globally unique again.');
        }

        if (DB::getDriverName() !== 'sqlite') {
            // MySQL moved the platform_id foreign key onto the composite indexes; give it its own index back first.
            Schema::table('transactions', function (Blueprint $table) {
                $table->index('platform_id', 'transactions_platform_id_foreign');
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['platform_id', 'paid_date']);
            $table->dropIndex(['platform_id', 'markaz_created_at']);
            $table->dropIndex(['platform_id', 'markaz_ref_id']);
            $table->dropUnique(['platform_id', 'markaz_transaction_id']);
            $table->dropIndex(['trx_id']);
            $table->unique('trx_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'markaz_transaction_id',
                'markaz_customer_id',
                'markaz_ref_id',
                'markaz_created_at',
                'markaz_paid_at',
                'source_status',
                'effective_status',
                'is_claim_coupon',
                'product_price',
                'voucher_discount',
                'admin_fee',
                'net_nominal',
                'customer_name',
                'customer_phone',
                'synced_at',
            ]);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->date('paid_date')->nullable(false)->change();
            $table->unsignedBigInteger('markaz_user_id')->nullable(false)->change();
        });
    }
};
