<?php

namespace HiEvents\Services\Domain\Auth;

use Exception;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Interfaces\DomainObjectInterface;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Auth\AuthManager;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Payload;

readonly class AuthUserService
{
    public function __construct(
        /**
         * @var AuthManager
         */
        private AuthManager $authManager,
        private AccountUserRepositoryInterface $accountUserRepository,
    ) {}

    public function getAuthenticatedAccountId(): ?int
    {
        if (! $this->authManager->check()) {
            return null;
        }

        try {
            /** @var Payload $payload */
            $payload = $this->authManager->payload();
        } catch (JWTException) {
            return null;
        }

        $accountId = $payload->get('account_id');

        if ($accountId !== null) {
            return $accountId;
        }

        // Token minted while this user belonged to no account — a ticket buyer
        // who has since been promoted to organizer. Resolve it from the
        // database so their existing session keeps working without a re-login,
        // but only when unambiguous: a user with several accounts must keep the
        // account-chooser behaviour, which deliberately yields null.
        return $this->resolveSoleAccountId();
    }

    private function resolveSoleAccountId(): ?int
    {
        $userId = $this->authManager->id();

        if ($userId === null) {
            return null;
        }

        $memberships = $this->accountUserRepository->findWhere([
            'user_id' => (int) $userId,
        ]);

        if (count($memberships) !== 1) {
            return null;
        }

        return $memberships->first()->getAccountId();
    }

    public function getAuthenticatedUserRole(): ?Role
    {
        if (! $this->authManager->check()) {
            return null;
        }

        try {
            /** @var Payload $payload */
            $payload = $this->authManager->payload();
        } catch (JWTException) {
            return null;
        }

        try {
            return Role::from($payload->get('role'));
        } catch (Exception) {
            return null;
        }
    }

    public function getUser(): UserDomainObject|DomainObjectInterface|null
    {
        /** @var User $user */
        if ($user = $this->authManager->user()) {
            $user = UserDomainObject::hydrateFromModel($user);

            if ($accountId = $this->getAuthenticatedAccountId()) {
                $user->setCurrentAccountUser($this->accountUserRepository->findFirstWhere([
                    'user_id' => $user->getId(),
                    'account_id' => $accountId,
                ]));
            }

            return $user;
        }

        return null;
    }
}
