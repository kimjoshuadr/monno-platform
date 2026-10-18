<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizers can opt an event out of platform ticketing entirely and point
 * buyers at their own registration surface instead. `ticketing_mode` defaults
 * to PLATFORM so every existing event keeps selling here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', static function (Blueprint $table) {
            $table->string('ticketing_mode', 20)->default('PLATFORM');
            $table->string('external_ticket_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', static function (Blueprint $table) {
            $table->dropColumn('ticketing_mode');
            $table->dropColumn('external_ticket_url');
        });
    }
};
