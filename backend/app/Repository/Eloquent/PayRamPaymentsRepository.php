<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\PayramPaymentDomainObject;
use HiEvents\Models\PayramPayment;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;

/**
 * @extends BaseRepository<PayramPaymentDomainObject>
 */
class PayRamPaymentsRepository extends BaseRepository implements PayRamPaymentsRepositoryInterface
{
    protected function getModel(): string
    {
        return PayramPayment::class;
    }

    public function getDomainObject(): string
    {
        return PayramPaymentDomainObject::class;
    }
}
