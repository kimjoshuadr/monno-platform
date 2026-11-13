<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payram_payments', static function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');

            $table->string('reference_id')->unique()->comment('PayRam reference_id - the idempotency key for this payment');
            $table->string('invoice_id')->nullable()->comment('Our own reference (order short id), echoed back in webhooks');
            $table->string('customer_id')->nullable()->comment('Organizer/buyer identifier sent to PayRam');
            $table->string('checkout_url')->nullable()->comment('Hosted PayRam checkout URL the buyer is redirected to');

            // Money: orders are priced in the event currency, PayRam charges in USD only.
            $table->decimal('amount_in_usd', 18, 6)->comment('Amount charged to the buyer in USD');
            $table->string('order_currency', 10)->comment('Currency of the order, e.g. PHP');
            $table->decimal('order_amount', 14, 2)->comment('Order total in order currency');
            $table->decimal('fx_rate', 18, 10)->nullable()->comment('USD per 1 unit of order currency at quote time');
            $table->decimal('platform_fee_usd', 18, 6)->nullable()->comment('Platform fee portion, in USD');

            // Fill state
            $table->string('status', 30)->default('OPEN')->comment('OPEN | PARTIALLY_FILLED | FILLED | OVER_FILLED | CANCELLED');
            $table->string('currency', 20)->nullable()->comment('Crypto token chosen by the buyer, e.g. USDC');
            $table->string('network', 20)->nullable()->comment('Blockchain code chosen by the buyer, e.g. BASE');
            $table->decimal('filled_amount', 18, 6)->nullable();
            $table->decimal('filled_amount_in_usd', 18, 6)->nullable();
            $table->unsignedInteger('confirmation_current')->nullable();
            $table->unsignedInteger('confirmation_required')->nullable();

            // On-chain details (source address is where a refund must be sent)
            $table->string('source_address', 128)->nullable()->comment("Buyer's sending address - refund destination");
            $table->string('destination_address', 128)->nullable()->comment('Deposit address that received the funds');
            $table->string('transaction_hash', 128)->nullable();
            $table->jsonb('payment_info')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('last_webhook_payload')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payram_payments');
    }
};
