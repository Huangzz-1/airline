<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->unsignedTinyInteger('outbound_count')->nullable();
            $t->text('contact_details')->nullable();
        });
        Schema::table('reservation_passengers', fn (Blueprint $t) => $t->text('details')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Export trip contact/passenger details before rolling back.');
    }
};
