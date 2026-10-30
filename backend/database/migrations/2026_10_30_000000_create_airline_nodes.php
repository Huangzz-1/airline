<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ops')->create('airports', function (Blueprint $t) {
            $t->string('code', 3)->primary();
            $t->string('name');
            $t->string('city');
            $t->string('timezone');
        });
        Schema::connection('ops')->create('aircraft', function (Blueprint $t) {
            $t->id();
            $t->string('registration')->unique();
            $t->string('type');
            $t->unsignedInteger('capacity');
            $t->string('status')->default('AVAILABLE');
        });
        Schema::connection('ops')->create('flights', function (Blueprint $t) {
            $t->id();
            $t->string('number');
            $t->string('origin', 3);
            $t->string('destination', 3);
            $t->foreign('origin')->references('code')->on('airports');
            $t->foreign('destination')->references('code')->on('airports');
            $t->foreignId('aircraft_id')->constrained('aircraft');
            $t->dateTime('departure');
            $t->dateTime('arrival');
            $t->string('status')->default('SCHEDULED');
            $t->string('gate');
            $t->unique(['number', 'departure']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('passenger');
            $t->string('airport_code', 3)->nullable();
            $t->timestamp('consented_at')->nullable();
        });
        Schema::create('inventory', function (Blueprint $t) {
            $t->unsignedBigInteger('flight_id')->primary(); // DB1 reference: deliberately no cross-server FK
            $t->unsignedInteger('capacity');
            $t->unsignedBigInteger('price');
            $t->string('currency', 3)->default('VND');
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('reference')->unique();
            $t->foreignId('user_id')->constrained();
            $t->unsignedBigInteger('flight_id');
            $t->foreign('flight_id')->references('flight_id')->on('inventory');
            $t->string('idempotency_key', 80);
            $t->unique(['user_id', 'idempotency_key']);
            $t->string('status')->index();
            $t->unsignedBigInteger('amount');
            $t->dateTime('expires_at')->index();
            $t->string('seat')->nullable();
            $t->unique(['flight_id', 'seat']);
            $t->string('payment_scenario')->nullable();
            $t->timestamps();
        });
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->uuid('booking_id')->unique();
            $t->foreign('booking_id')->references('id')->on('bookings');
            $t->string('number')->unique();
            $t->timestamps();
        });
        Schema::create('boarding_passes', function (Blueprint $t) {
            $t->id();
            $t->uuid('booking_id')->unique();
            $t->foreign('booking_id')->references('id')->on('bookings');
            $t->string('token_hash', 64)->unique();
            $t->text('token_encrypted');
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::connection('finance')->create('payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('booking_id')->unique();
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('VND');
            $t->string('status');
            $t->uuid('correlation_id');
            $t->timestamps();
        });
        foreach (['mysql', 'finance'] as $node) {
            Schema::connection($node)->create('outbox', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('type');
                $t->json('payload');
                $t->unsignedInteger('attempts')->default(0);
                $t->dateTime('available_at')->index();
                $t->dateTime('delivered_at')->nullable();
                $t->timestamps();
            });
            Schema::connection($node)->create('inbox', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->timestamp('received_at');
            });
            Schema::connection($node)->create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('action');
                $t->string('object_id');
                $t->string('before_state')->nullable();
                $t->string('after_state')->nullable();
                $t->uuid('correlation_id');
                $t->timestamp('created_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['mysql', 'finance'] as $node) {
            foreach (['audit_logs', 'inbox', 'outbox'] as $table) {
                Schema::connection($node)->dropIfExists($table);
            }
        }
        Schema::connection('finance')->dropIfExists('payments');
        foreach (['boarding_passes', 'tickets', 'bookings', 'inventory'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'airport_code', 'consented_at']));
        foreach (['flights', 'aircraft', 'airports'] as $table) {
            Schema::connection('ops')->dropIfExists($table);
        }
    }
};
