<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('ops')->table('flights', function (Blueprint $t) {
            $t->string('carrier_code', 2)->default('AS');
            $t->string('demo_source', 30)->nullable()->index();
            $t->index(['carrier_code', 'departure']);
        });
    }

    public function down(): void
    {
        Schema::connection('ops')->table('flights', function (Blueprint $t) {
            $t->dropIndex(['carrier_code', 'departure']);
            $t->dropIndex(['demo_source']);
            $t->dropColumn(['carrier_code', 'demo_source']);
        });
    }
};
