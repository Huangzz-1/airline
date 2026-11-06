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

        if (! $ops->hasTable('crew_rosters')) {
            $ops->create('crew_rosters', function (Blueprint $t) {
                $t->id();
                $t->foreignId('crew_member_id')->constrained('crew_members')->cascadeOnDelete();
                $t->dateTime('starts_at');
                $t->dateTime('ends_at');
                $t->string('type', 20)->default('DUTY');
                $t->string('status', 20)->default('PLANNED');
                $t->string('reason')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->index(['crew_member_id', 'starts_at', 'ends_at']);
            });
        }
        if (! $ops->hasTable('flight_actual_events')) {
            $ops->create('flight_actual_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
                $t->string('type', 30);
                $t->dateTime('event_time');
                $t->string('source', 30)->default('MANUAL');
                $t->string('reason')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['flight_id', 'type', 'event_time']);
            });
        }
        if (! $ops->hasTable('ground_equipment')) {
            $ops->create('ground_equipment', function (Blueprint $t) {
                $t->id();
                $t->string('airport_code', 3);
                $t->string('code', 60);
                $t->string('name', 160);
                $t->string('type', 40);
                $t->string('status', 20)->default('AVAILABLE');
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->foreign('airport_code')->references('code')->on('airports');
                $t->unique(['airport_code', 'code']);
            });
        }
        if (! $ops->hasTable('ground_task_equipment')) {
            $ops->create('ground_task_equipment', function (Blueprint $t) {
                $t->id();
                $t->foreignId('ground_task_id')->constrained('ground_tasks')->cascadeOnDelete();
                $t->foreignId('equipment_id')->constrained('ground_equipment')->cascadeOnDelete();
                $t->unsignedInteger('quantity')->default(1);
                $t->timestamps();
                $t->unique(['ground_task_id', 'equipment_id']);
            });
        }
        if (! $ops->hasTable('ground_task_handoffs')) {
            $ops->create('ground_task_handoffs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('ground_task_id')->constrained('ground_tasks')->cascadeOnDelete();
                $t->unsignedBigInteger('from_user')->nullable();
                $t->unsignedBigInteger('to_user');
                $t->text('note')->nullable();
                $t->dateTime('handed_at');
                $t->timestamps();
            });
        }
        if (! $ops->hasTable('maintenance_materials')) {
            $ops->create('maintenance_materials', function (Blueprint $t) {
                $t->id();
                $t->string('code', 60)->unique();
                $t->string('name', 180);
                $t->string('unit', 20)->default('EA');
                $t->unsignedInteger('stock')->default(0);
                $t->unsignedInteger('reorder_level')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! $ops->hasTable('work_order_materials')) {
            $ops->create('work_order_materials', function (Blueprint $t) {
                $t->id();
                $t->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
                $t->foreignId('material_id')->constrained('maintenance_materials');
                $t->unsignedInteger('quantity');
                $t->unsignedBigInteger('unit_cost')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->unique(['work_order_id', 'material_id']);
            });
        }

        if (! $commerce->hasTable('passenger_document_verifications')) {
            $commerce->create('passenger_document_verifications', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('reservation_passenger_id')->index();
                $t->string('document_type', 30);
                $t->string('document_hash', 64);
                $t->string('status', 20)->default('PENDING');
                $t->text('result')->nullable();
                $t->unsignedBigInteger('verified_by')->nullable();
                $t->dateTime('verified_at')->nullable();
                $t->timestamps();
            });
        }
        if (! $commerce->hasTable('passenger_assistance_requests')) {
            $commerce->create('passenger_assistance_requests', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('reservation_id')->index();
                $t->uuid('passenger_id')->index();
                $t->string('type', 40);
                $t->string('status', 20)->default('OPEN');
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->dateTime('resolved_at')->nullable();
                $t->timestamps();
            });
        }
        if (! $commerce->hasTable('notification_deliveries')) {
            $commerce->create('notification_deliveries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('channel', 20);
                $t->string('status', 20)->default('PENDING');
                $t->string('provider_ref')->nullable();
                $t->text('error')->nullable();
                $t->dateTime('sent_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'channel', 'status']);
            });
        }
        if (! $commerce->hasTable('support_compensations')) {
            $commerce->create('support_compensations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
                $t->unsignedBigInteger('amount')->default(0);
                $t->string('currency', 3)->default('VND');
                $t->string('reason', 255);
                $t->string('status', 20)->default('PENDING');
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->dateTime('approved_at')->nullable();
                $t->timestamps();
            });
        }
        if (! $commerce->hasTable('analytics_datasets')) {
            $commerce->create('analytics_datasets', function (Blueprint $t) {
                $t->id();
                $t->string('code', 80)->unique();
                $t->string('name', 160);
                $t->string('source', 80);
                $t->json('schema_definition')->nullable();
                $t->string('status', 20)->default('DRAFT');
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
            });
        }
        if (! $commerce->hasTable('analytics_model_runs')) {
            $commerce->create('analytics_model_runs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('dataset_id')->constrained('analytics_datasets')->cascadeOnDelete();
                $t->string('model_type', 60);
                $t->decimal('train_ratio', 5, 4)->default(0.8);
                $t->string('status', 20)->default('QUEUED');
                $t->json('metrics')->nullable();
                $t->string('artifact_ref')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->index(['dataset_id', 'status']);
            });
        }
        if (! $commerce->hasTable('analytics_recommendations')) {
            $commerce->create('analytics_recommendations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('model_run_id')->constrained('analytics_model_runs')->cascadeOnDelete();
                $t->string('target_type', 60);
                $t->string('target_id', 80);
                $t->text('recommendation');
                $t->decimal('confidence', 6, 5)->nullable();
                $t->json('explanation')->nullable();
                $t->string('status', 20)->default('PROPOSED');
                $t->timestamps();
            });
        }

        if (! $finance->hasTable('reconciliation_imports')) {
            $finance->create('reconciliation_imports', function (Blueprint $t) {
                $t->id();
                $t->string('source', 60);
                $t->string('file_hash', 64);
                $t->string('filename');
                $t->unsignedInteger('row_count')->default(0);
                $t->string('status', 20)->default('IMPORTED');
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->unique(['source', 'file_hash']);
            });
        }
    }

    public function down(): void
    {
        foreach (['reconciliation_imports'] as $table) Schema::connection('finance')->dropIfExists($table);
        foreach (['analytics_recommendations', 'analytics_model_runs', 'analytics_datasets', 'support_compensations', 'notification_deliveries', 'passenger_assistance_requests', 'passenger_document_verifications'] as $table) Schema::connection('mysql')->dropIfExists($table);
        foreach (['work_order_materials', 'maintenance_materials', 'ground_task_handoffs', 'ground_task_equipment', 'ground_equipment', 'flight_actual_events', 'crew_rosters'] as $table) Schema::connection('ops')->dropIfExists($table);
    }
};
