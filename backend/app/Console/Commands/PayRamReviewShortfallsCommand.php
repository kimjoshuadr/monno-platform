<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\PayramPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\PayRamPaymentStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Lists PayRam payments that took money but cannot settle on their own.
 *
 * A PARTIALLY_FILLED payment is a confirmed on-chain transfer that did not
 * cover the invoice. PayRam offers no way to top it up, so the order stays
 * RESERVED and the buyer has paid for a ticket they have not received. This
 * command is the manual-review worklist: who paid, how much arrived, how much
 * is missing, and the transaction to look up.
 *
 * It most often happens at very small amounts, where the crypto quantity
 * PayRam displays is rounded too coarsely for the invoice to be matched.
 */
class PayRamReviewShortfallsCommand extends Command
{
    protected $signature = 'monno:payram-shortfalls
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'List PayRam payments that were partially filled and need manual review.';

    public function __construct(
        private readonly PayRamPaymentsRepositoryInterface $paymentsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payments = $this->paymentsRepository->findWhere([
            PayramPaymentDomainObjectAbstract::STATUS => PayRamPaymentStatus::PARTIALLY_FILLED->value,
        ]);

        if ($payments->isEmpty()) {
            $this->info('No partial PayRam payments. Nothing needs manual review.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($payments as $payment) {
            $order = $this->orderRepository
                ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
                ->findById($payment->getOrderId());

            $expected = (float) $payment->getAmountInUsd();
            $received = (float) ($payment->getFilledAmountInUsd() ?? 0);

            $rows[] = [
                'reference' => (string) $payment->getReferenceId(),
                'order' => $order?->getShortId() ?? (string) $payment->getOrderId(),
                'buyer' => (string) ($order?->getEmail() ?? ''),
                'expected_usd' => number_format($expected, 6),
                'received_usd' => number_format($received, 6),
                'shortfall_usd' => number_format(max(0, $expected - $received), 6),
                'currency' => (string) ($payment->getCurrency() ?? ''),
                'transaction' => (string) ($payment->getTransactionHash() ?? ''),
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $this->error(sprintf('%d PayRam payment(s) are stranded and need manual review.', count($rows)));
        $this->table(
            ['Reference', 'Order', 'Buyer', 'Expected', 'Received', 'Short', 'Coin', 'Transaction'],
            array_map(static fn (array $row): array => [
                $row['reference'],
                $row['order'],
                $row['buyer'],
                '$'.$row['expected_usd'],
                '$'.$row['received_usd'],
                '$'.$row['shortfall_usd'],
                $row['currency'],
                $row['transaction'],
            ], $rows),
        );

        $this->newLine();
        $this->line('The buyer has paid and the ticket is not issued. Settle or refund deliberately.');

        return self::FAILURE;
    }
}
