<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ops')->table('aircraft', fn (Blueprint $t) => $t->json('seat_layout')->nullable());
        Schema::table('inventory', fn (Blueprint $t) => $t->json('seat_layout')->nullable());
        Schema::table('aircraft_change_receipts', fn (Blueprint $t) => $t->json('seat_layout')->nullable());
        Schema::create('reservations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference', 8)->unique();
            $t->foreignId('user_id')->constrained();
            $t->string('trip_type', 20);
            $t->string('idempotency_key', 80);
            $t->unique(['user_id', 'idempotency_key']);
            $t->string('request_hash', 64);
            $t->unsignedBigInteger('total_amount');
            $t->dateTime('expires_at');
            $t->timestamps();
        });
        Schema::create('reservation_passengers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('reservation_id');
            $t->foreign('reservation_id')->references('id')->on('reservations');
            $t->unsignedTinyInteger('position');
            $t->string('name', 100);
            $t->date('date_of_birth')->nullable();
            $t->unique(['reservation_id', 'position']);
        });
        Schema::create('reservation_segments', function (Blueprint $t) {
            $t->id();
            $t->uuid('reservation_id');
            $t->foreign('reservation_id')->references('id')->on('reservations');
            $t->unsignedTinyInteger('position');
            $t->unsignedBigInteger('flight_id');
            $t->foreign('flight_id')->references('flight_id')->on('inventory');
            $t->string('fare_class', 20);
            $t->unique(['reservation_id', 'position']);
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->uuid('reservation_id')->nullable();
            $t->foreign('reservation_id')->references('id')->on('reservations');
            $t->uuid('passenger_id')->nullable();
            $t->foreign('passenger_id')->references('id')->on('reservation_passengers');
            $t->foreignId('reservation_segment_id')->nullable()->constrained('reservation_segments');
            $t->boolean('reservation_initial')->default(false);
        });
        Schema::table('checkout_sessions', function (Blueprint $t) {
            $t->dropUnique(['intent_id']);
            $t->index('intent_id');
        });
        Schema::connection('finance')->table('checkout_intents', function (Blueprint $t) {
            $t->uuid('reservation_id')->nullable()->index();
            $t->json('items')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('reservations')->exists()) {
            throw new RuntimeException('Export reservation data before rolling back this feature.');
        }
        Schema::connection('finance')->table('checkout_intents', fn (Blueprint $t) => $t->dropColumn(['reservation_id', 'items']));
        Schema::table('checkout_sessions', function (Blueprint $t) {
            $t->dropIndex(['intent_id']);
            $t->unique('intent_id');
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropForeign(['reservation_id']);
            $t->dropForeign(['passenger_id']);
            $t->dropConstrainedForeignId('reservation_segment_id');
            $t->dropColumn(['reservation_id', 'passenger_id', 'reservation_initial']);
        });
        Schema::dropIfExists('reservation_segments');
        Schema::dropIfExists('reservation_passengers');
        Schema::dropIfExists('reservations');
        Schema::table('aircraft_change_receipts', fn (Blueprint $t) => $t->dropColumn('seat_layout'));
        Schema::table('inventory', fn (Blueprint $t) => $t->dropColumn('seat_layout'));
        Schema::connection('ops')->table('aircraft', fn (Blueprint $t) => $t->dropColumn('seat_layout'));
    }
};
