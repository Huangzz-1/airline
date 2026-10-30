<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('support_cases');
            $t->unsignedBigInteger('sender_id');
            $t->string('sender_role');
            $t->text('body');
            $t->dateTime('created_at');
        });
        foreach (DB::table('support_cases')->orderBy('id')->cursor() as $c) {
            DB::table('support_messages')->insert(['case_id' => $c->id, 'sender_id' => $c->user_id, 'sender_role' => 'passenger', 'body' => $c->message, 'created_at' => $c->created_at]);
            if ($c->response) {
                DB::table('support_messages')->insert(['case_id' => $c->id, 'sender_id' => $c->handled_by ?? 0, 'sender_role' => 'customer_service', 'body' => $c->response, 'created_at' => $c->updated_at]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
