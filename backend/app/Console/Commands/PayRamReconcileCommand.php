<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\PayRamPaymentStatus;
use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\DTO\PayRamWebhookDTO;
use HiEvents\Services\Domain\Payment\PayRam\PayRamCredentialResolver;
use HiEvents\Services\Domain\Payment\PayRam\PayRamIncomingWebhookHandler;
use HiEvents\Services\Domain\Payment\PayRam\PayRamStatusPayloadMapper;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconciles payments PayRam knows about but never told us about.
 *
 * Webhook endpoint registration is not available on our PayRam build yet, so
 * this is the settlement trigger: it polls the status endpoint for payments
 * still awaiting money and feeds the same idempotent handler a webhook would
 * have reached. When webhook registration ships, deliveries become the fast
 * path and this stays as the safety net.
 */
class PayRamReconcileCommand extends Command
{
    protected $signature = 'monno:payram-reconcile
        {--limit=50 : Maximum open payments to reconcile in one run}
        {--sleep=0 : Milliseconds to pause between payments, to stay gentle on the gateway}';

    protected $description = 'Poll PayRam for payments whose webhook was missed and settle them.';

    public function __construct(
        private readonly PayRamPaymentsRepositoryInterface $paymentsRepository,
        private readonly PayRamClient $payRamClient,
        private readonly PayRamIncomingWebhookHandler $webhookHandler,
        private readonly PayRamStatusPayloadMapper $mapper,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PayRamCredentialResolver $credentialResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $openPayments = $this->paymentsRepository->findWhereIn('status', [
            PayRamPaymentStatus::OPEN->value,
            PayRamPaymentStatus::PARTIALLY_FILLED->value,
        ]);

        $limit = max(1, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));

        $checked = 0;
        $settled = 0;
        $failed = 0;
        $shortfalls = 0;

        foreach ($openPayments->slice(0, $limit) as $payment) {
            $referenceId = $payment->getReferenceId();

            try {
                $status = $this->payRamClient->getPaymentStatus(
                    $referenceId,
                    $this->apiKeyFor($payment),
                );
                $payload = $this->mapper->toWebhookPayload($status);

                if ($payload === null) {
                    $failed++;
                    $this->warn(sprintf('No usable status for %s', $referenceId));

                    continue;
                }

                $checked++;

                $this->webhookHandler->handle(new PayRamWebhookDTO(
                    rawBody: (string) json_encode($payload),
                    signature: null,
                ));

                if ($payload['status'] === PayRamPaymentStatus::FILLED->value
                    || $payload['status'] === PayRamPaymentStatus::OVER_FILLED->value) {
                    $settled++;
                    $this->info(sprintf('Settled %s (order %s)', $referenceId, $payload['invoice_id'] ?? '?'));

                    continue;
                }

                // A partial fill is a confirmed on-chain transfer that did not
                // cover the invoice, and it cannot be topped up. It will never
                // settle on its own, so call it out rather than silently leaving
                // it open forever.
                if ($payload['status'] === PayRamPaymentStatus::PARTIALLY_FILLED->value) {
                    $shortfalls++;
                    $expected = isset($payload['amount']) ? (float) $payload['amount'] : null;
                    $received = isset($payload['filled_amount_in_usd']) ? (float) $payload['filled_amount_in_usd'] : null;
                    $this->error(sprintf(
                        'SHORTFALL %s (order %s): received $%s of $%s — needs manual review, cannot settle automatically',
                        $referenceId,
                        $payload['invoice_id'] ?? '?',
                        $received !== null ? number_format($received, 6) : '?',
                        $expected !== null ? number_format($expected, 6) : '?',
                    ));
                }
            } catch (PayRamApiException $exception) {
                $failed++;
                $this->warn(sprintf('Gateway error for %s: %s', $referenceId, $exception->getMessage()));
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf('Could not reconcile %s: %s', $referenceId, $exception->getMessage()));
            }

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        $this->info(sprintf(
            'Reconciled: %d checked, %d settled, %d short, %d failed, %d open total.',
            $checked,
            $settled,
            $shortfalls,
            $failed,
            $openPayments->count(),
        ));

        return $shortfalls > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Payments made under an organizer's merchant account can only be read
     * with that organizer's project key.
     */
    private function apiKeyFor($payment): ?string
    {
        $invoiceId = $payment->getInvoiceId();
        if ($invoiceId === null || $invoiceId === '') {
            return null;
        }

        $order = $this->orderRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findByShortId($invoiceId);

        return $this->credentialResolver->forOrganizer($order?->getEvent()?->getOrganizerId());
    }
}
