<?php

namespace HiEvents\DomainObjects;

class OrganizerPayramAccountDomainObject extends Generated\OrganizerPayramAccountDomainObjectAbstract
{
    final public const WALLET_ADDRESS = 'wallet_address';
    final public const SUPPORTED_CURRENCIES = 'supported_currencies';

    protected ?string $wallet_address = null;
    /** @var array<string>|null */
    protected ?array $supported_currencies = null;

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'wallet_address' => $this->wallet_address,
            'supported_currencies' => $this->supported_currencies,
        ]);
    }

    public function getWalletAddress(): ?string
    {
        return $this->wallet_address;
    }

    public function setWalletAddress(?string $wallet_address): self
    {
        $this->wallet_address = $wallet_address;
        return $this;
    }

    /**
     * @return array<string>|null
     */
    public function getSupportedCurrencies(): ?array
    {
        return $this->supported_currencies;
    }

    /**
     * @param array<string>|null $supported_currencies
     */
    public function setSupportedCurrencies(?array $supported_currencies): self
    {
        $this->supported_currencies = $supported_currencies;
        return $this;
    }
}
