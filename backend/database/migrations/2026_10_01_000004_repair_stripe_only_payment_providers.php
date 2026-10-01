<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Card payments are off deployment-wide, so an event stored with only
        // STRIPE resolves to no usable method: checkout would have nothing to
        // offer and the publish gate would block it. These rows are the old seed
        // default, not a decision anyone made — those events were taking money
        // offline in practice.
        DB::table('event_settings')
            ->whereRaw('payment_providers::text = ?', ['["STRIPE"]'])
            ->update(['payment_providers' => json_encode(['OFFLINE'])]);
    }

    public function down(): void
    {
        // Deliberately not reversible: the original value carried no intent.
    }
};
