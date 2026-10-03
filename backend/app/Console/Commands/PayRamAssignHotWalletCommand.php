<?php

namespace HiEvents\Console\Commands;

use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Attaches the shared operator hot wallet to merchant projects that are missing
 * it.
 *
 * A project with no hot wallet cannot sweep: buyer funds sit in the deposit
 * addresses until someone assigns one, and PayRam has no fallback to a shared
 * wallet. Provisioning does this automatically now, but projects created before
 * that (or where the assignment failed) need a repair pass. The assignment is
 * idempotent, so this is safe to run repeatedly.
 */
class PayRamAssignHotWalletCommand extends Command
{
    protected $signature = 'monno:payram-assign-hot-wallet
        {--wallet= : Hot wallet id to assign (defaults to services.payram.hot_wallet_id)}
        {--dry-run : List what would be assigned without changing anything}';

    protected $description = 'Ensure every PayRam merchant project has the shared hot wallet assigned.';

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $hotWalletId = (int) ($this->option('wallet') ?: config('services.payram.hot_wallet_id', 0));

        if ($hotWalletId <= 0) {
            $this->error('No hot wallet id configured. Pass --wallet=ID or set PAYRAM_HOT_WALLET_ID.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $checked = 0;
        $assigned = 0;
        $failed = 0;

        foreach ($this->accountsRepository->all() as $account) {
            $projectId = $account->getExternalPlatformId();
            if ($projectId === null) {
                continue;
            }

            $checked++;
            $label = sprintf('#%d %s', $projectId, (string) ($account->getProjectName() ?? ''));

            if ($dryRun) {
                $this->line(sprintf('  would assign %s to hot wallet %d', $label, $hotWalletId));

                continue;
            }

            try {
                $this->operatorClient->assignHotWallet($projectId, $hotWalletId);
                $assigned++;
                $this->info(sprintf('  assigned %s', $label));
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf('  failed %s: %s', $label, $exception->getMessage()));
            }
        }

        $this->info(sprintf(
            'Done: %d projects checked, %d assigned, %d failed (hot wallet %d%s).',
            $checked,
            $assigned,
            $failed,
            $hotWalletId,
            $dryRun ? ', dry run' : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
