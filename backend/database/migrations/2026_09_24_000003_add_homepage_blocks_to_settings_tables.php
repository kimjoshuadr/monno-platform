<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The section list each homepage is assembled from. Stored as one jsonb array
 * per surface, matching how homepage_theme_settings already works, so a single
 * PATCH …/settings writes the whole page.
 *
 * Block shape: {id, type, visible, settings:{…}} with `type` in HomepageBlockType.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->addColumn('jsonb', 'homepage_blocks')->nullable();
        });

        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->addColumn('jsonb', 'homepage_blocks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn('homepage_blocks');
        });

        Schema::table('organizer_settings', function (Blueprint $table) {
            $table->dropColumn('homepage_blocks');
        });
    }
};
