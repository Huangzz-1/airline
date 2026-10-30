<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('source_booking_id');
            $t->foreign('source_booking_id')->references('id')->on('bookings');
            $t->uuid('target_booking_id')->unique();
            $t->foreign('target_booking_id')->references('id')->on('bookings');
            $t->unsignedBigInteger('requested_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->string('status')->default('PENDING');
            $t->string('reason');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_requests');
    }
};
