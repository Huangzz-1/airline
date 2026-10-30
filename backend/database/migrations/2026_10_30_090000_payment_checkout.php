<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->string('payment_method')->nullable());
        Schema::create('checkout_sessions', function (Blueprint $t) {
            $t->uuid('booking_id')->primary();
            $t->foreign('booking_id')->references('id')->on('bookings');
            $t->uuid('intent_id')->unique();
            $t->string('method');
            $t->string('status')->default('CREATED');
            $t->text('pay_url')->nullable();
            $t->json('bank_details')->nullable();
            $t->dateTime('expires_at');
            $t->string('customer_reference', 120)->nullable();
            $t->timestamp('reported_at')->nullable();
            $t->timestamps();
        });
        Schema::connection('finance')->create('checkout_intents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('booking_id')->unique();
            $t->string('reference', 20);
            $t->string('method');
            $t->unsignedBigInteger('amount');
            $t->string('status')->default('CREATED');
            $t->text('pay_url')->nullable();
            $t->json('bank_details')->nullable();
            $t->string('ip_address', 45);
            $t->dateTime('expires_at');
            $t->string('customer_reference', 120)->nullable();
            $t->timestamp('reported_at')->nullable();
            $t->string('provider_transaction', 120)->nullable();
            $t->unique(['method', 'provider_transaction']);
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
        Schema::connection('finance')->table('payments', fn (Blueprint $t) => $t->string('method')->nullable());
        Schema::connection('finance')->table('refunds', function (Blueprint $t) {
            $t->string('payout_reference', 120)->nullable()->unique();
            $t->text('payout_details')->nullable();
            $t->string('destination_hash', 64)->nullable();
            $t->unsignedBigInteger('settled_by')->nullable();
            $t->timestamp('settled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('finance')->table('refunds', fn (Blueprint $t) => $t->dropColumn(['payout_reference', 'payout_details', 'destination_hash', 'settled_by', 'settled_at']));
        Schema::connection('finance')->table('payments', fn (Blueprint $t) => $t->dropColumn('method'));
        Schema::connection('finance')->dropIfExists('checkout_intents');
        Schema::dropIfExists('checkout_sessions');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('payment_method'));
    }
};
