<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verifications', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users');
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::table('checkout_sessions', fn (Blueprint $t) => $t->string('mode')->default('gateway'));
        Schema::connection('finance')->table('checkout_intents', fn (Blueprint $t) => $t->string('mode')->default('gateway'));
        Schema::connection('finance')->table('payments', fn (Blueprint $t) => $t->string('mode')->default('simulator'));
    }

    public function down(): void
    {
        Schema::connection('finance')->table('payments', fn (Blueprint $t) => $t->dropColumn('mode'));
        Schema::connection('finance')->table('checkout_intents', fn (Blueprint $t) => $t->dropColumn('mode'));
        Schema::table('checkout_sessions', fn (Blueprint $t) => $t->dropColumn('mode'));
        Schema::dropIfExists('email_verifications');
    }
};
