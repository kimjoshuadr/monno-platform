<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Models\OrganizerPayramAccount;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerPayramAccountDomainObject>
 */
class OrganizerPayRamAccountsRepository extends BaseRepository implements OrganizerPayRamAccountsRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerPayramAccount::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerPayramAccountDomainObject::class;
    }
}
