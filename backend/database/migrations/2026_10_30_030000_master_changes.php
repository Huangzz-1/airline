<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ops')->create('master_changes', function (Blueprint $t) {
            $t->id();
            $t->string('entity');
            $t->string('record_id');
            $t->json('before_data');
            $t->json('after_data');
            $t->string('reason');
            $t->string('status')->default('PENDING');
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('ops')->dropIfExists('master_changes');
    }
};
