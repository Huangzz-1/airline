<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection('mysql');
        if (! $db->hasTable('analytics_dataset_records')) {
            $db->create('analytics_dataset_records', function (Blueprint $t) {
                $t->id();
                $t->foreignId('dataset_id')->constrained('analytics_datasets')->cascadeOnDelete();
                $t->string('event_key', 120);
                $t->json('payload');
                $t->string('status', 20)->default('RAW');
                $t->text('quality_errors')->nullable();
                $t->dateTime('cleaned_at')->nullable();
                $t->timestamps();
                $t->unique(['dataset_id', 'event_key']);
                $t->index(['dataset_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('analytics_dataset_records');
    }
};
