<?php

use HiEvents\DomainObjects\Enums\PaymentProviders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stripe is disabled for this deployment (see the frontend STRIPE_ENABLED flag), so
 * offline payment is the only method an event can offer. Events stored with a Stripe-only
 * or empty provider list would otherwise have no payable method at checkout.
 *
 * Data-only: no schema changes. Idempotent — rows already offering offline payments are
 * left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_settings')
            ->select(['id', 'payment_providers'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $providers = is_array($row->payment_providers)
                        ? $row->payment_providers
                        : (json_decode((string) $row->payment_providers, true) ?: []);

                    if (in_array(PaymentProviders::OFFLINE->name, $providers, true)) {
                        continue;
                    }

                    DB::table('event_settings')
                        ->where('id', $row->id)
                        ->update(['payment_providers' => [PaymentProviders::OFFLINE->name]]);
                }
            });
    }

    public function down(): void
    {
        // noop — the previous per-event provider lists are not recoverable
    }
};
