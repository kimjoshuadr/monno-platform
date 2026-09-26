<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The monno website is fed entirely from the platform database now, so the
     * real content lives in `monno:seed` (organizer accounts + LIVE events). Run
     * it directly for options; `db:seed` runs it with the wipe + no prompt.
     */
    public function run(): void
    {
        $this->call('monno:seed', ['--force' => true]);
    }
}
