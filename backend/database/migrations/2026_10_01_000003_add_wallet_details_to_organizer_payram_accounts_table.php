<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_payram_accounts', static function (Blueprint $table) {
            $table->string('wallet_address', 120)->nullable()->after('wallet_status')->comment('Self-custody cold payout wallet address');
            $table->json('supported_currencies')->nullable()->after('wallet_address')->comment('Array of enabled crypto currencies/networks');
        });
    }

    public function down(): void
    {
        Schema::table('organizer_payram_accounts', static function (Blueprint $table) {
            $table->dropColumn(['wallet_address', 'supported_currencies']);
        });
    }
};
