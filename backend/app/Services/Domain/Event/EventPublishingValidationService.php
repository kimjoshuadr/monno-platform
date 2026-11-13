<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Exceptions\CannotPublishEventWithoutPaymentMethodException;
use HiEvents\Services\Domain\Payment\PayRam\PayRamGatewayStatusService;
use Illuminate\Support\Facades\DB;

class EventPublishingValidationService
{
    /**
     * An event with paid tickets (price > 0) MUST have at least one active payment
     * provider (PAYRAM, STRIPE, or OFFLINE) properly configured before it can be published.
     * Events with only free tickets can be published freely.
     *
     * @throws CannotPublishEventWithoutPaymentMethodException
     */
    public function validateCanPublish(int $eventId, ?int $organizerId): void
    {
        $hasPaidTickets = DB::table('products')
            ->join('product_prices', 'products.id', '=', 'product_prices.product_id')
            ->where('products.event_id', $eventId)
            ->whereNull('products.deleted_at')
            ->whereNull('product_prices.deleted_at')
            ->where('product_prices.price', '>', 0)
            ->exists();

        if (! $hasPaidTickets) {
            return;
        }

        $eventSettings = DB::table('event_settings')
            ->where('event_id', $eventId)
            ->first();

        $providers = [];
        if ($eventSettings && ! empty($eventSettings->payment_providers)) {
            $decoded = is_string($eventSettings->payment_providers)
                ? json_decode($eventSettings->payment_providers, true)
                : $eventSettings->payment_providers;
            if (is_array($decoded)) {
                $providers = $decoded;
            }
        }

        // Mirror the client's resolvePaymentProviders(): drop methods this
        // deployment cannot process, but never add one. Offline is a choice now
        // that crypto is a real gateway, not a forced fallback.
        if (! config('app.stripe_enabled')) {
            $providers = array_values(array_diff($providers, [PaymentProviders::STRIPE->value]));
        }

        $hasOffline = in_array(PaymentProviders::OFFLINE->value, $providers, true);
        if ($hasOffline) {
            return;
        }

        $hasPayRam = in_array(PaymentProviders::PAYRAM->value, $providers, true);
        if ($hasPayRam && $organizerId !== null) {
            // PayRam is the authority on whether money can actually move, and
            // the organizer wires their wallets up in the PayRam console — not
            // here. So the only evidence that counts is the gateway's own
            // confirmation, read live rather than inferred from a stored field.
            $account = DB::table('organizer_payram_accounts')
                ->where('organizer_id', $organizerId)
                ->where('status', 'READY')
                ->first();

            if ($account !== null) {
                $gatewayStatus = app(PayRamGatewayStatusService::class)
                    ->forProject($account->external_platform_id ?? null);

                if ($gatewayStatus['available'] && $gatewayStatus['cold_wallet_configured']) {
                    return;
                }
            }
        }

        $hasStripe = in_array(PaymentProviders::STRIPE->value, $providers, true);
        if ($hasStripe) {
            $stripeReady = false;
            if (config('app.saas_mode_enabled')) {
                $stripeReady = $organizerId !== null && DB::table('organizer_stripe_platforms')
                    ->where('organizer_id', $organizerId)
                    ->where('stripe_connect_setup_complete', true)
                    ->exists();
            } else {
                $stripeReady = (bool) config('services.stripe.secret_key');
            }

            if ($stripeReady) {
                return;
            }
        }

        throw new CannotPublishEventWithoutPaymentMethodException;
    }
}
