<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Accounts;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Resources\Account\AccountResource;
use HiEvents\Services\Domain\Account\AccountDeletionService;
use Illuminate\Http\JsonResponse;

class GetAccountAction extends BaseAction
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly AccountDeletionService $accountDeletionService,
    ) {}

    public function __invoke(?int $accountId = null): JsonResponse
    {
        // Guests keep the ordinary rejection below.
        if (! $this->isUserAuthenticated()) {
            $this->getAuthenticatedAccountId();
        }

        $authenticatedAccountId = $this->getAuthenticatedAccountIdOrNull();

        // Onboarding's first-time organizer form calls this to prefill currency
        // and name. A ticket buyer has no account yet, and a 403 here sent the
        // frontend's axios interceptor to /auth/login in the middle of
        // onboarding. Null is the truthful answer — the form guards for it.
        if ($authenticatedAccountId === null) {
            return new JsonResponse(['data' => null]);
        }

        $this->minimumAllowedRole(Role::ORGANIZER);

        $account = $this->accountRepository->findById($authenticatedAccountId);
        $account->setActiveDeletionRequest($this->accountDeletionService->findActiveRequest($authenticatedAccountId));

        return $this->resourceResponse(AccountResource::class, $account);
    }
}
