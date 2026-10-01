<?php

namespace HiEvents\DomainObjects;

class PayramPaymentDomainObject extends Generated\PayramPaymentDomainObjectAbstract
{
    private ?OrderDomainObject $order = null;

    public function getOrder(): ?OrderDomainObject
    {
        return $this->order;
    }

    public function setOrder(?OrderDomainObject $order): self
    {
        $this->order = $order;

        return $this;
    }
}
