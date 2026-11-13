<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the wallet details we used to collect on our side.
 *
 * The wizard stored a payout address and a currency list, but nothing ever read
 * them: they were never sent to PayRam and never used to create a payment. The
 * console printed the address as if it were live configuration, which is worse
 * than useless — it claimed the organizer had done something they had not.
 *
 * The wallets are configured in PayRam, and its own status is the only thing we
 * report (`wallet_status`, refreshed from the gateway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_payram_accounts', static function (Blueprint $table) {
            $table->dropColumn(['wallet_address', 'supported_currencies']);
        });
    }

    public function down(): void
    {
        Schema::table('organizer_payram_accounts', static function (Blueprint $table) {
            $table->string('wallet_address', 120)->nullable()->after('wallet_status')->comment('Self-custody cold payout wallet address');
            $table->json('supported_currencies')->nullable()->after('wallet_address')->comment('Array of enabled crypto currencies/networks');
        });
    }
};
