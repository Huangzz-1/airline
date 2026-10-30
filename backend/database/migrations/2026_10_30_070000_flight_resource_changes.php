<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('ops');
        $s->create('flight_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('flight_id')->constrained('flights');
            $t->string('kind');
            $t->string('status')->default('PENDING');
            $t->unsignedInteger('source_version');
            $t->string('previous_status');
            $t->json('before_data');
            $t->json('after_data');
            $t->string('reason');
            $t->string('failure_reason')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
        $s->table('flights', fn (Blueprint $t) => $t->uuid('pending_change_id')->nullable()->index());
        $s->table('crew_assignments', fn (Blueprint $t) => $t->boolean('active')->default(true)->index());
        $s->create('change_reservations', function (Blueprint $t) {
            $t->id();
            $t->uuid('change_id');
            $t->foreign('change_id')->references('id')->on('flight_changes');
            $t->foreignId('aircraft_id')->constrained('aircraft');
            $t->foreignId('flight_id')->constrained('flights');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->unique(['change_id', 'aircraft_id']);
            $t->index(['aircraft_id', 'starts_at', 'ends_at']);
        });
        Schema::table('inventory', function (Blueprint $t) {
            $t->uuid('frozen_change_id')->nullable()->index();
            $t->boolean('manual_closed')->default(false);
        });
        DB::table('inventory')->where('status', 'CLOSED')->update(['manual_closed' => true]);
        Schema::create('aircraft_change_receipts', function (Blueprint $t) {
            $t->uuid('change_id')->primary();
            $t->unsignedBigInteger('flight_id');
            $t->unsignedBigInteger('target_aircraft_id');
            $t->unsignedInteger('fence_version');
            $t->unsignedInteger('old_capacity');
            $t->string('old_status');
            $t->unsignedInteger('new_capacity');
            $t->unsignedInteger('requested_capacity');
            $t->dateTime('departure');
            $t->string('flight_number');
            $t->string('status');
            $t->string('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('seat_reassignments', function (Blueprint $t) {
            $t->id();
            $t->uuid('change_id');
            $t->foreign('change_id')->references('change_id')->on('aircraft_change_receipts');
            $t->uuid('booking_id');
            $t->foreign('booking_id')->references('id')->on('bookings');
            $t->string('old_seat')->nullable();
            $t->string('new_seat');
            $t->dateTime('created_at');
            $t->unique(['change_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_reassignments');
        Schema::dropIfExists('aircraft_change_receipts');
        Schema::table('inventory', fn (Blueprint $t) => $t->dropColumn(['frozen_change_id', 'manual_closed']));
        $s = Schema::connection('ops');
        $s->dropIfExists('change_reservations');
        $s->table('crew_assignments', fn (Blueprint $t) => $t->dropColumn('active'));
        $s->table('flights', fn (Blueprint $t) => $t->dropColumn('pending_change_id'));
        $s->dropIfExists('flight_changes');
    }
};
