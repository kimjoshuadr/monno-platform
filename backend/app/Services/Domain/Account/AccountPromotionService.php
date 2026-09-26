<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Account;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AccountConfigurationRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use Illuminate\Config\Repository;

/**
 * Gives an account-less user their own organizer tenant.
 *
 * monno signs ticket buyers up as bare `users` rows — no `accounts`, no
 * `account_users` — so every organizer endpoint (keyed on account_id) refused
 * them. Provisioning the account here keeps a single identity: their orders
 * stay attached to the same user while they gain an organizer tenant, instead
 * of forcing a second signup with a different email.
 *
 * Retry-safe: once the user has exactly one membership, AuthUserService
 * resolves that id from the database even though the JWT was minted without an
 * `account_id` claim, so promotion never runs twice for the same user.
 */
readonly class AccountPromotionService
{
    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private AccountConfigurationRepositoryInterface $accountConfigurationRepository,
        private AccountUserAssociationService $accountUserAssociationService,
        private Repository $config,
    ) {}

    public function promote(UserDomainObject $user): int
    {
        $account = $this->accountRepository->create([
            'name' => $this->accountName($user),
            'email' => strtolower($user->getEmail()),
            'timezone' => $user->getTimezone(),
            'currency_code' => $this->config->get('app.default_currency_code') ?? 'USD',
            'short_id' => IdHelper::shortId(IdHelper::ACCOUNT_PREFIX),
            // Buyers verify their email at signup; the tenant inherits that.
            'account_verified_at' => now()->toDateTimeString(),
            'account_configuration_id' => $this->defaultConfigurationId(),
            'account_messaging_tier_id' => $this->config->get('app.is_hi_events') ? 1 : 3,
        ]);

        $this->accountUserAssociationService->associate(
            user: $user,
            account: $account,
            role: Role::ADMIN,
            status: UserStatus::ACTIVE,
            isAccountOwner: true,
        );

        return $account->getId();
    }

    private function accountName(UserDomainObject $user): string
    {
        $name = trim($user->getFirstName().' '.($user->getLastName() ?? ''));

        return $name !== '' ? $name : $user->getEmail();
    }

    /**
     * Nullable column, so a missing default configuration degrades instead of
     * blocking onboarding entirely.
     */
    private function defaultConfigurationId(): ?int
    {
        $configuration = $this->accountConfigurationRepository->findFirstWhere([
            'is_system_default' => true,
        ]);

        return $configuration?->getId();
    }
}
