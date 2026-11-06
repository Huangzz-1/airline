<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ops = Schema::connection('ops');
        $commerce = Schema::connection('mysql');
        $finance = Schema::connection('finance');

        if (! $ops->hasTable('airport_areas')) {
            $ops->create('airport_areas', function (Blueprint $t) {
                $t->id();
                $t->string('airport_code', 3);
                $t->unsignedBigInteger('parent_id')->nullable();
                $t->string('code', 40);
                $t->string('name', 160);
                $t->string('type', 30);
                $t->boolean('active')->default(true);
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->foreign('airport_code')->references('code')->on('airports');
                $t->foreign('parent_id')->references('id')->on('airport_areas')->nullOnDelete();
                $t->unique(['airport_code', 'code']);
                $t->index(['airport_code', 'type', 'active']);
            });
        }
        if ($ops->hasTable('airports')) {
            $ops->table('airports', function (Blueprint $t) use ($ops) {
                if (! $ops->hasColumn('airports', 'icao')) $t->string('icao', 4)->nullable()->unique();
                if (! $ops->hasColumn('airports', 'country_code')) $t->string('country_code', 2)->nullable()->index();
                if (! $ops->hasColumn('airports', 'latitude')) $t->decimal('latitude', 9, 6)->nullable();
                if (! $ops->hasColumn('airports', 'longitude')) $t->decimal('longitude', 9, 6)->nullable();
                if (! $ops->hasColumn('airports', 'operating_hours')) $t->json('operating_hours')->nullable();
            });
        }
        if (! $ops->hasTable('flight_seasons')) {
            $ops->create('flight_seasons', function (Blueprint $t) {
                $t->id();
                $t->string('code', 60)->unique();
                $t->foreignId('route_id')->constrained('routes');
                $t->foreignId('aircraft_id')->nullable()->constrained('aircraft');
                $t->date('from_date');
                $t->date('to_date');
                $t->json('weekdays');
                $t->time('local_departure');
                $t->unsignedInteger('duration_minutes');
                $t->unsignedBigInteger('sale_price')->default(0);
                $t->string('status', 20)->default('DRAFT');
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamps();
                $t->index(['status', 'from_date', 'to_date']);
            });
        }
        if (! $ops->hasTable('aircraft_models')) {
            $ops->create('aircraft_models', function (Blueprint $t) {
                $t->id();
                $t->string('code', 40)->unique();
                $t->string('manufacturer', 100);
                $t->string('name', 120);
                $t->unsignedInteger('default_capacity')->nullable();
                $t->json('cabin_layout')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if ($ops->hasTable('aircraft') && ! $ops->hasColumn('aircraft', 'model_id')) {
            $ops->table('aircraft', function (Blueprint $t) {
                $t->foreignId('model_id')->nullable()->after('id')->constrained('aircraft_models')->nullOnDelete();
            });
        }
        if (! $ops->hasTable('crew_qualifications')) {
            $ops->create('crew_qualifications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('crew_member_id')->constrained('crew_members')->cascadeOnDelete();
                $t->string('code', 80);
                $t->date('valid_from')->nullable();
                $t->date('valid_to')->nullable();
                $t->string('document_ref', 120)->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->unique(['crew_member_id', 'code']);
            });
        }
        if (! $ops->hasTable('crew_availability')) {
            $ops->create('crew_availability', function (Blueprint $t) {
                $t->id();
                $t->foreignId('crew_member_id')->constrained('crew_members')->cascadeOnDelete();
                $t->dateTime('starts_at');
                $t->dateTime('ends_at');
                $t->string('status', 20)->default('AVAILABLE');
                $t->string('reason')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->index(['crew_member_id', 'starts_at', 'ends_at']);
            });
        }
        if (! $ops->hasTable('maintenance_items')) {
            $ops->create('maintenance_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('aircraft_id')->constrained('aircraft')->cascadeOnDelete();
                $t->string('code', 60);
                $t->string('title', 180);
                $t->unsignedInteger('interval_hours')->nullable();
                $t->unsignedInteger('interval_cycles')->nullable();
                $t->unsignedInteger('interval_days')->nullable();
                $t->dateTime('next_due_at')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->unique(['aircraft_id', 'code']);
            });
        }
        if (! $ops->hasTable('work_order_items')) {
            $ops->create('work_order_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
                $t->foreignId('maintenance_item_id')->nullable()->constrained('maintenance_items')->nullOnDelete();
                $t->string('description', 255);
                $t->string('status', 20)->default('OPEN');
                $t->unsignedInteger('quantity')->default(1);
                $t->text('evidence')->nullable();
                $t->timestamps();
            });
        }
        if (! $ops->hasTable('ground_services')) {
            $ops->create('ground_services', function (Blueprint $t) {
                $t->id();
                $t->string('airport_code', 3);
                $t->string('code', 50);
                $t->string('name', 160);
                $t->unsignedInteger('default_duration_minutes')->default(30);
                $t->boolean('mandatory')->default(false);
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->foreign('airport_code')->references('code')->on('airports');
                $t->unique(['airport_code', 'code']);
            });
        }
        if (! $ops->hasTable('ground_task_services')) {
            $ops->create('ground_task_services', function (Blueprint $t) {
                $t->id();
                $t->foreignId('ground_task_id')->constrained('ground_tasks')->cascadeOnDelete();
                $t->foreignId('service_id')->constrained('ground_services')->cascadeOnDelete();
                $t->unsignedInteger('sequence')->default(1);
                $t->timestamps();
                $t->unique(['ground_task_id', 'service_id']);
            });
        }
        if (! $ops->hasTable('integration_connections')) {
            $ops->create('integration_connections', function (Blueprint $t) {
                $t->id();
                $t->string('code', 80)->unique();
                $t->string('name', 160);
                $t->string('protocol', 30);
                $t->string('endpoint')->nullable();
                $t->json('configuration')->nullable();
                $t->string('status', 20)->default('DISABLED');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }

        if (! $commerce->hasTable('fare_rule_versions')) {
            $commerce->create('fare_rule_versions', function (Blueprint $t) {
                $t->id();
                $t->string('fare_code', 20);
                $t->string('name', 120);
                $t->json('rules');
                $t->dateTime('effective_from');
                $t->dateTime('effective_until')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->index(['fare_code', 'effective_from', 'effective_until'], 'fare_rules_effective_idx');
            });
        }
        if (! $commerce->hasTable('baggage_allowances')) {
            $commerce->create('baggage_allowances', function (Blueprint $t) {
                $t->id();
                $t->string('fare_code', 20);
                $t->unsignedInteger('pieces')->default(0);
                $t->decimal('included_kg', 8, 2)->default(0);
                $t->unsignedBigInteger('excess_kg_price')->default(0);
                $t->dateTime('effective_from');
                $t->dateTime('effective_until')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->index(['fare_code', 'effective_from']);
            });
        }
        if (! $commerce->hasTable('reservation_ancillaries')) {
            $commerce->create('reservation_ancillaries', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('reservation_id')->index();
                $t->uuid('booking_id')->nullable()->index();
                $t->string('type', 40);
                $t->unsignedInteger('quantity')->default(1);
                $t->unsignedBigInteger('amount')->default(0);
                $t->string('currency', 3)->default('VND');
                $t->string('status', 20)->default('PENDING');
                $t->string('idempotency_key', 80)->unique();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }
        if (! $commerce->hasTable('baggage_incidents')) {
            $commerce->create('baggage_incidents', function (Blueprint $t) {
                $t->id();
                $t->foreignId('baggage_id')->constrained('baggage')->cascadeOnDelete();
                $t->string('type', 30);
                $t->string('status', 20)->default('OPEN');
                $t->text('description');
                $t->unsignedBigInteger('opened_by');
                $t->unsignedBigInteger('resolved_by')->nullable();
                $t->dateTime('resolved_at')->nullable();
                $t->timestamps();
                $t->index(['baggage_id', 'status']);
            });
        }
        if (! $commerce->hasTable('notification_templates')) {
            $commerce->create('notification_templates', function (Blueprint $t) {
                $t->id();
                $t->string('code', 100);
                $t->string('channel', 20);
                $t->string('locale', 10)->default('vi');
                $t->string('subject')->nullable();
                $t->text('body');
                $t->unsignedInteger('version')->default(1);
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->unique(['code', 'channel', 'locale', 'version']);
            });
        }
        if (! $commerce->hasTable('customer_interactions')) {
            $commerce->create('customer_interactions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('type', 40);
                $t->string('reference_type', 60)->nullable();
                $t->string('reference_id', 80)->nullable();
                $t->text('summary');
                $t->json('metadata')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'created_at']);
            });
        }
        if (! $commerce->hasTable('system_configurations')) {
            $commerce->create('system_configurations', function (Blueprint $t) {
                $t->id();
                $t->string('scope', 60)->default('global');
                $t->string('key', 120);
                $t->json('value');
                $t->unsignedInteger('version')->default(1);
                $t->dateTime('effective_from')->nullable();
                $t->dateTime('effective_until')->nullable();
                $t->unsignedBigInteger('updated_by');
                $t->timestamps();
                $t->unique(['scope', 'key', 'version']);
            });
        }

        if (! $finance->hasTable('fee_catalog')) {
            $finance->create('fee_catalog', function (Blueprint $t) {
                $t->id();
                $t->string('code', 60)->unique();
                $t->string('name', 160);
                $t->string('category', 40);
                $t->unsignedBigInteger('amount')->default(0);
                $t->string('currency', 3)->default('VND');
                $t->json('conditions')->nullable();
                $t->dateTime('effective_from');
                $t->dateTime('effective_until')->nullable();
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
            });
        }
        if (! $finance->hasTable('reconciliation_reason_codes')) {
            $finance->create('reconciliation_reason_codes', function (Blueprint $t) {
                $t->id();
                $t->string('code', 60)->unique();
                $t->string('label', 160);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! $finance->hasTable('reconciliation_adjustments')) {
            $finance->create('reconciliation_adjustments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('run_id')->constrained('reconciliation_runs');
                $t->foreignId('entry_id')->constrained('reconciliation_entries');
                $t->foreignId('reason_code_id')->nullable()->constrained('reconciliation_reason_codes')->nullOnDelete();
                $t->bigInteger('amount');
                $t->text('explanation');
                $t->string('status', 20)->default('PENDING');
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['reconciliation_adjustments', 'reconciliation_reason_codes', 'fee_catalog'] as $table) {
            Schema::connection('finance')->dropIfExists($table);
        }
        foreach (['system_configurations', 'customer_interactions', 'notification_templates', 'baggage_incidents', 'reservation_ancillaries', 'baggage_allowances', 'fare_rule_versions'] as $table) {
            Schema::connection('mysql')->dropIfExists($table);
        }
        $ops = Schema::connection('ops');
        if ($ops->hasTable('aircraft') && $ops->hasColumn('aircraft', 'model_id')) {
            $ops->table('aircraft', fn (Blueprint $t) => $t->dropConstrainedForeignId('model_id'));
        }
        foreach (['integration_connections', 'ground_task_services', 'ground_services', 'work_order_items', 'maintenance_items', 'crew_availability', 'crew_qualifications', 'aircraft_models', 'flight_seasons', 'airport_areas'] as $table) {
            $ops->dropIfExists($table);
        }
        if ($ops->hasTable('airports')) {
            $ops->table('airports', function (Blueprint $t) use ($ops) {
                foreach (['icao', 'country_code', 'latitude', 'longitude', 'operating_hours'] as $column) {
                    if ($ops->hasColumn('airports', $column)) $t->dropColumn($column);
                }
            });
        }
    }
};
