<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->string('fare_class', 20)->default('ECONOMY'));
        Schema::create('fare_classes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('flight_id');
            $t->foreign('flight_id')->references('flight_id')->on('inventory');
            $t->string('code', 20);
            $t->string('name');
            $t->unsignedInteger('quota');
            $t->unsignedBigInteger('price');
            $t->boolean('active')->default(true);
            $t->unique(['flight_id', 'code']);
        });
        Schema::create('booking_changes', function (Blueprint $t) {
            $t->uuid('request_id')->primary();
            $t->uuid('booking_id');
            $t->foreign('booking_id')->references('id')->on('bookings');
            $t->unsignedBigInteger('flight_id');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_changes');
        Schema::dropIfExists('fare_classes');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('fare_class'));
    }
};
