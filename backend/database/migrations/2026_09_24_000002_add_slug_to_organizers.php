<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizer handle. Previously the "slug" was computed from the name on every
 * read (Str::slug($name)), so it could never be chosen — this stores one when
 * supplied and leaves NULL for the derived-from-name behaviour.
 *
 * Unique because the public site addresses rooms as /o/{handle}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizers', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('organizers', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
