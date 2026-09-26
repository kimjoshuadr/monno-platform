<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields the public monno site renders on event pages but which had no
 * column at all: a short lede, a featured flag for rails/hero, cover alt text,
 * and the run-of-show list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('tagline', 200)->nullable();
            $table->boolean('featured')->default(false);
            $table->string('image_alt', 255)->nullable();
            // jsonb to match `attributes` / `recurrence_rule` on this table.
            $table->addColumn('jsonb', 'agenda')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'featured', 'image_alt', 'agenda']);
        });
    }
};
