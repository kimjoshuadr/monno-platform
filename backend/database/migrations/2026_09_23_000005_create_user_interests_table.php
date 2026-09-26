<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two rows per interest kind rather than a JSON blob, so "which cities
        // does this person follow" is a real query and stays indexed.
        Schema::create('user_interests', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('value', 120);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'kind', 'value']);
            $table->index(['kind', 'value']);
            // `kind` is limited to category|city by request validation.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_interests');
    }
};
