<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ops = Schema::connection('ops');
        $ops->table('aircraft', function (Blueprint $t) {
            $t->decimal('flight_hours', 12, 2)->default(0);
            $t->unsignedInteger('cycles')->default(0);
        });
        $ops->create('routes', function (Blueprint $t) {
            $t->id();
            $t->string('origin', 3);
            $t->string('destination', 3);
            $t->foreign('origin')->references('code')->on('airports');
            $t->foreign('destination')->references('code')->on('airports');
            $t->unsignedInteger('duration_minutes');
            $t->unsignedInteger('distance_km');
            $t->boolean('active')->default(true);
            $t->unique(['origin', 'destination']);
        });
        $ops->create('airport_resources', function (Blueprint $t) {
            $t->id();
            $t->string('airport_code', 3);
            $t->foreign('airport_code')->references('code')->on('airports');
            $t->string('code');
            $t->string('type');
            $t->boolean('active')->default(true);
            $t->unique(['airport_code', 'code']);
        });
        $ops->table('flights', function (Blueprint $t) {
            $t->foreignId('route_id')->nullable()->constrained('routes');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('sale_price')->default(1290000);
            $t->dateTime('actual_departure')->nullable();
            $t->dateTime('actual_arrival')->nullable();
        });
        $ops->create('crew_members', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('position');
            $t->string('qualification');
            $t->date('license_expires');
            $t->boolean('available')->default(true);
        });
        $ops->create('crew_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flight_id')->constrained('flights');
            $t->foreignId('crew_member_id')->constrained('crew_members');
            $t->unsignedBigInteger('assigned_by');
            $t->dateTime('created_at');
            $t->unique(['flight_id', 'crew_member_id']);
        });
        $ops->create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('aircraft_id')->constrained('aircraft');
            $t->string('title');
            $t->dateTime('due_at');
            $t->string('status')->default('OPEN');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->unsignedBigInteger('released_by')->nullable();
            $t->text('evidence')->nullable();
            $t->timestamps();
        });
        $ops->create('resource_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flight_id')->constrained('flights');
            $t->foreignId('resource_id')->constrained('airport_resources');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->unsignedBigInteger('actor_id');
            $t->string('reason');
            $t->timestamps();
        });
        $ops->create('ground_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flight_id')->constrained('flights');
            $t->string('title');
            $t->unsignedBigInteger('assigned_to');
            $t->dateTime('due_at');
            $t->boolean('mandatory')->default(true);
            $t->string('status')->default('PENDING');
            $t->text('evidence')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });
        $ops->create('disruptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flight_id')->constrained('flights');
            $t->string('type');
            $t->string('reason');
            $t->unsignedInteger('delay_minutes')->default(0);
            $t->string('status')->default('PENDING');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
        $ops->create('devices', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('airport_code', 3);
            $t->decimal('threshold', 10, 2);
            $t->unsignedInteger('last_sequence')->default(0);
            $t->decimal('latest_value', 10, 2)->nullable();
            $t->dateTime('last_event_at')->nullable();
        });
        $ops->create('telemetry', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('device_id')->constrained('devices');
            $t->unsignedInteger('sequence');
            $t->decimal('value', 10, 2);
            $t->dateTime('event_time');
            $t->dateTime('received_at');
            $t->boolean('late');
            $t->boolean('alert');
            $t->unique(['device_id', 'sequence']);
        });
        foreach (['outbox', 'inbox', 'audit_logs'] as $table) {
            $ops->create($table, function (Blueprint $t) use ($table) {
                if ($table === 'outbox') {
                    $t->uuid('id')->primary();
                    $t->string('type');
                    $t->json('payload');
                    $t->unsignedInteger('attempts')->default(0);
                    $t->dateTime('available_at')->index();
                    $t->dateTime('delivered_at')->nullable();
                    $t->timestamps();
                } elseif ($table === 'inbox') {
                    $t->uuid('id')->primary();
                    $t->timestamp('received_at');
                } else {
                    $t->id();
                    $t->unsignedBigInteger('actor_id')->nullable();
                    $t->string('action');
                    $t->string('object_id');
                    $t->string('before_state')->nullable();
                    $t->string('after_state')->nullable();
                    $t->uuid('correlation_id');
                    $t->timestamp('created_at');
                }
            });
        }
    }

    public function down(): void
    {
        $ops = Schema::connection('ops');
        foreach (['audit_logs', 'inbox', 'outbox', 'telemetry', 'devices', 'disruptions', 'ground_tasks', 'resource_allocations', 'work_orders', 'crew_assignments', 'crew_members'] as $t) {
            $ops->dropIfExists($t);
        }
        $ops->table('flights', function (Blueprint $t) {
            $t->dropConstrainedForeignId('route_id');
            $t->dropColumn(['created_by', 'approved_by', 'published_at', 'version', 'sale_price', 'actual_departure', 'actual_arrival']);
        });
        $ops->dropIfExists('airport_resources');
        $ops->dropIfExists('routes');
        $ops->table('aircraft', fn (Blueprint $t) => $t->dropColumn(['flight_hours', 'cycles']));
    }
};
