<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ops = Schema::connection('ops');
        if ($ops->hasTable('airports') && ! $ops->hasColumn('airports', 'active')) {
            $ops->table('airports', fn (Blueprint $t) => $t->boolean('active')->default(true)->index());
        }
    }

    public function down(): void
    {
        $ops = Schema::connection('ops');
        if ($ops->hasTable('airports') && $ops->hasColumn('airports', 'active')) {
            $ops->table('airports', fn (Blueprint $t) => $t->dropColumn('active'));
        }
    }
};
