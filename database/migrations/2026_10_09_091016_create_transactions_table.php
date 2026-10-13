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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained();
            $table->unsignedBigInteger('markaz_user_id');
            $table->string('trx_id')->unique();
            $table->date('paid_date');
            $table->time('paid_time')->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->decimal('nominal', 15, 2);
            $table->json('promo_code')->nullable();
            $table->string('product')->nullable();
            $table->string('product_type', 100)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('status_message', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
