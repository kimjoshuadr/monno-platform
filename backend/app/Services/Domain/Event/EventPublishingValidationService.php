<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Exceptions\CannotPublishEventWithoutPaymentMethodException;
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

        $hasOffline = in_array(PaymentProviders::OFFLINE->value, $providers, true);
        if ($hasOffline) {
            return;
        }

        $hasPayRam = in_array(PaymentProviders::PAYRAM->value, $providers, true);
        if ($hasPayRam && $organizerId !== null) {
            $payramReady = DB::table('organizer_payram_accounts')
                ->where('organizer_id', $organizerId)
                ->where('status', 'READY')
                ->where(function ($query) {
                    $query->where('wallet_status', 'READY')
                        ->orWhereNotNull('wallet_address');
                })
                ->exists();

            if ($payramReady) {
                return;
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

        throw new CannotPublishEventWithoutPaymentMethodException();
    }
}
