<?php

use HiEvents\DomainObjects\Enums\EventCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the SPORTS category to FITNESS across existing events.
 *
 * The check constraint is dropped first so the rows can be rewritten before the
 * new value set (read from the enum) is enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_category_check');
        DB::statement("UPDATE events SET category = 'FITNESS' WHERE category = 'SPORTS'");

        $values = EventCategory::valuesArray();
        $quoted = implode(', ', array_map(fn ($v) => "'".$v."'", $values));

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_category_check CHECK (category IN ($quoted))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_category_check');
        DB::statement("UPDATE events SET category = 'SPORTS' WHERE category = 'FITNESS'");

        $values = array_map(
            fn ($v) => $v === 'FITNESS' ? 'SPORTS' : $v,
            EventCategory::valuesArray()
        );
        $quoted = implode(', ', array_map(fn ($v) => "'".$v."'", $values));

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_category_check CHECK (category IN ($quoted))");
    }
};
