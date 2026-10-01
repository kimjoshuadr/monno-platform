<?php

namespace HiEvents\Services\Domain\Payment\PayRam;

/**
 * Turns PayRam's status response (camelCase) into the payload shape the webhook
 * handler already understands, so a polled status and a delivered webhook take
 * exactly the same path through settlement.
 */
class PayRamStatusPayloadMapper
{
    /**
     * @param  array<string, mixed>  $statusResponse  GET /api/v1/payment/reference/{id}
     * @return array<string, mixed>|null null when there is nothing useful to sync
     */
    public function toWebhookPayload(array $statusResponse): ?array
    {
        $referenceId = (string) ($statusResponse['referenceID'] ?? '');
        $state = (string) ($statusResponse['paymentState'] ?? '');

        if ($referenceId === '' || $state === '') {
            return null;
        }

        $depositAddress = $statusResponse['depositAddress'] ?? null;
        $transactionHash = $statusResponse['explorerTransaction'] ?? null;
        $paymentInfo = null;

        if (is_string($depositAddress) && $depositAddress !== '') {
            $paymentInfo = [[
                // The status endpoint only exposes our side of the transfer; the
                // buyer's source address arrives on webhook deliveries instead.
                'destination_address' => $depositAddress,
                'transaction_hash' => is_string($transactionHash) && $transactionHash !== '' ? $transactionHash : null,
            ]];
        }

        return array_filter([
            'reference_id' => $referenceId,
            'invoice_id' => $statusResponse['invoiceID'] ?? null,
            'customer_id' => $statusResponse['customerID'] ?? null,
            'status' => $state,
            'amount' => $statusResponse['amountInUSD'] ?? null,
            'currency' => $statusResponse['blockchainSymbol'] ?? $statusResponse['currencySymbol'] ?? null,
            'filled_amount' => $statusResponse['filledAmount'] ?? null,
            'filled_amount_in_usd' => $statusResponse['filledAmountInUSD'] ?? null,
            'confirmation_current' => $statusResponse['confirmationCurrent'] ?? null,
            'confirmation_required' => $statusResponse['confirmationRequired'] ?? null,
            'payment_info' => $paymentInfo,
            'polled' => true,
        ], static fn ($value) => $value !== null);
    }
}
