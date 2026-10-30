<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $t) {
            $t->string('status')->default('OPEN');
            $t->unsignedInteger('source_version')->default(1);
            $t->string('fare_class')->default('ECONOMY');
        });
        Schema::create('baggage', function (Blueprint $t) {
            $t->id();
            $t->string('tag')->unique();
            $t->string('request_id', 80)->unique();
            $t->uuid('booking_id');
            $t->foreign('booking_id')->references('id')->on('bookings');
            $t->decimal('weight', 5, 2);
            $t->string('status')->default('TAGGED');
            $t->string('custody');
            $t->timestamps();
        });
        Schema::create('baggage_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('baggage_id')->constrained('baggage');
            $t->string('status');
            $t->string('location');
            $t->string('custody');
            $t->unsignedBigInteger('actor_id');
            $t->dateTime('created_at');
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users');
            $t->string('title');
            $t->text('body');
            $t->dateTime('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('support_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->uuid('booking_id')->nullable();
            $t->string('type');
            $t->string('subject');
            $t->text('message');
            $t->string('status')->default('OPEN');
            $t->text('response')->nullable();
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamps();
        });
        $fin = Schema::connection('finance');
        $fin->create('refunds', function (Blueprint $t) {
            $t->id();
            $t->uuid('booking_id')->unique();
            $t->unsignedBigInteger('amount');
            $t->string('status')->default('REQUESTED');
            $t->string('reason');
            $t->unsignedBigInteger('requested_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
        $fin->create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('booking_id');
            $t->string('kind');
            $t->bigInteger('amount');
            $t->dateTime('created_at');
            $t->unique(['booking_id', 'kind']);
        });
        $fin->create('reconciliation_runs', function (Blueprint $t) {
            $t->id();
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status')->default('OPEN');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
        });
        $fin->create('reconciliation_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('reconciliation_runs');
            $t->uuid('booking_id');
            $t->string('result');
            $t->text('detail');
            $t->string('resolution')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->unique(['run_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        foreach (['reconciliation_entries', 'reconciliation_runs', 'ledger_entries', 'refunds'] as $t) {
            Schema::connection('finance')->dropIfExists($t);
        }
        foreach (['support_cases', 'notifications', 'baggage_events', 'baggage'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('inventory', fn (Blueprint $t) => $t->dropColumn(['status', 'source_version', 'fare_class']));
    }
};
