<?php

namespace HiEvents\Console\Commands;

use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\PayRamProjectProfileSyncService;
use Illuminate\Console\Command;

/**
 * Pushes the organizer's Monno profile onto their PayRam project (name, website,
 * support address). Useful as a one-off backfill and to repair drift.
 */
class PayRamSyncProjectProfileCommand extends Command
{
    protected $signature = 'monno:payram-sync-project-profile
        {--organizer= : Only sync this organizer id}';

    protected $description = 'Sync organizer profile details (name, website, support email) to their PayRam project.';

    public function __construct(
        private readonly PayRamProjectProfileSyncService $profileSync,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizerOption = $this->option('organizer');

        if ($organizerOption !== null && $organizerOption !== '') {
            $organizerIds = [(int) $organizerOption];
        } else {
            $organizerIds = [];
            foreach ($this->accountsRepository->all() as $account) {
                $organizerId = $account->getOrganizerId();
                if ($organizerId !== null) {
                    $organizerIds[] = (int) $organizerId;
                }
            }
        }

        foreach ($organizerIds as $organizerId) {
            $this->profileSync->syncForOrganizer($organizerId);
            $this->info(sprintf('synced organizer %d', $organizerId));
        }

        $this->info(sprintf('Done: %d organizer(s) synced.', count($organizerIds)));

        return self::SUCCESS;
    }
}
