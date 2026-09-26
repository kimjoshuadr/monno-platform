<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            // One review per person per event, enforced by the DB rather than
            // by an application check that could race.
            $table->unsignedSmallInteger('rating');
            $table->text('body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);
            $table->index('event_id');
            // The 1–5 range is enforced by request validation, not a CHECK
            // constraint: this Blueprint has no check() and raw DDL here would
            // buy little over validating at the edge.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
