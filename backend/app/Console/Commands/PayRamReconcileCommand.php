<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Status\PayRamPaymentStatus;
use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\DTO\PayRamWebhookDTO;
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

        foreach ($openPayments->slice(0, $limit) as $payment) {
            $referenceId = $payment->getReferenceId();

            try {
                $status = $this->payRamClient->getPaymentStatus($referenceId);
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
            'Reconciled: %d checked, %d settled, %d failed, %d open total.',
            $checked,
            $settled,
            $failed,
            $openPayments->count(),
        ));

        return self::SUCCESS;
    }
}
