<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ops')->table('flights', function (Blueprint $t) {
            $t->string('checkin_mode', 10)->default('AUTO');
            $t->dateTime('checkin_opens_at')->nullable();
            $t->dateTime('checkin_closes_at')->nullable();
        });
        Schema::table('fare_classes', fn (Blueprint $t) => $t->json('rules')->nullable());
        Schema::table('bookings', function (Blueprint $t) {
            $t->json('fare_rules')->nullable();
            $t->unsignedBigInteger('base_amount')->nullable();
            $t->unsignedInteger('baggage_kg')->default(0);
            $t->unsignedBigInteger('baggage_amount')->default(0);
            $t->unsignedBigInteger('change_fee_amount')->default(0);
        });
        Schema::table('exchange_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('change_fee')->default(0);
        });
        Schema::create('passenger_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('reservation_id')->index();
            $t->uuid('passenger_id');
            $t->unsignedBigInteger('requested_by');
            $t->string('fingerprint', 64);
            $t->text('before_data');
            $t->text('after_data');
            $t->string('status', 20);
            $t->string('reason');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
        Schema::create('boarding_manifests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('flight_id')->index();
            $t->unsignedInteger('version');
            $t->string('fingerprint', 64);
            $t->longText('snapshot');
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->unique(['flight_id', 'version']);
        });
        Schema::table('support_cases', function (Blueprint $t) {
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->string('priority', 10)->default('NORMAL');
            $t->dateTime('due_at')->nullable()->index();
            $t->dateTime('resolved_at')->nullable();
            $t->dateTime('escalated_at')->nullable();
        });
        Schema::create('recovery_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('booking_id')->index();
            $t->unsignedBigInteger('flight_id');
            $t->unsignedBigInteger('target_flight_id');
            $t->string('fare_class', 20);
            $t->unsignedBigInteger('quoted_amount');
            $t->string('fingerprint', 64);
            $t->string('status', 20)->default('PENDING');
            $t->string('reason');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('expires_at');
            $t->timestamps();
        });
        $fin = Schema::connection('finance');
        $fin->create('statement_batches', function (Blueprint $t) {
            $t->id();
            $t->string('source', 60);
            $t->string('filename');
            $t->string('file_hash', 64);
            $t->unsignedInteger('row_count');
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->unique(['source', 'file_hash']);
        });
        $fin->create('statement_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('statement_batches');
            $t->string('source', 60);
            $t->string('transaction_ref', 120);
            $t->string('booking_reference', 80);
            $t->unsignedBigInteger('amount');
            $t->string('currency', 3)->default('VND');
            $t->dateTime('booked_at');
            $t->string('payload_hash', 64);
            $t->string('result', 30);
            $t->uuid('intent_id')->nullable();
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->unique(['source', 'transaction_ref']);
        });
        $fin->create('financial_adjustments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('booking_id')->index();
            $t->unsignedBigInteger('entry_id')->nullable();
            $t->bigInteger('amount');
            $t->string('fingerprint', 64);
            $t->text('reason');
            $t->string('status', 20)->default('PENDING');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['financial_adjustments', 'statement_rows', 'statement_batches'] as $table) {
            Schema::connection('finance')->dropIfExists($table);
        }
        foreach (['recovery_offers', 'boarding_manifests', 'passenger_revisions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('support_cases', fn (Blueprint $t) => $t->dropColumn(['assigned_to', 'priority', 'due_at', 'resolved_at', 'escalated_at']));
        Schema::table('exchange_requests', fn (Blueprint $t) => $t->dropColumn('change_fee'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['fare_rules', 'base_amount', 'baggage_kg', 'baggage_amount', 'change_fee_amount']));
        Schema::table('fare_classes', fn (Blueprint $t) => $t->dropColumn('rules'));
        Schema::connection('ops')->table('flights', fn (Blueprint $t) => $t->dropColumn(['checkin_mode', 'checkin_opens_at', 'checkin_closes_at']));
    }
};
