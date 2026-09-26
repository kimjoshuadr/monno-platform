<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Auth;

use HiEvents\Http\Request\Auth\RegisterBuyerRequest;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\Auth\DTO\LoginCredentialsDTO;
use HiEvents\Services\Application\Handlers\Auth\LoginHandler;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Creates a ticket-buyer identity and signs them in.
 *
 * Deliberately unlike CreateAccountAction: it writes a `users` row only — no
 * `accounts`, no `organizers`, no `account_users` membership — so a buyer never
 * becomes a tenant. They are recognized as a team member precisely when an
 * account_users row exists for them.
 */
class RegisterBuyerAction extends BaseAuthAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly Hasher $hasher,
        private readonly LoginHandler $loginHandler,
    ) {}

    public function __invoke(RegisterBuyerRequest $request): JsonResponse
    {
        $email = strtolower($request->validated('email'));

        if ($this->userRepository->findFirstWhere(['email' => $email]) !== null) {
            throw ValidationException::withMessages([
                'email' => __('An account with this email already exists'),
            ]);
        }

        $this->userRepository->create([
            'email' => $email,
            'password' => $this->hasher->make($request->validated('password')),
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'timezone' => $request->validated('timezone') ?? 'UTC',
            'locale' => $request->validated('locale') ?? 'en',
            // Local installs verify on sign up; SaaS would send a confirmation
            // link instead, mirroring CreateAccountHandler.
            'email_verified_at' => now()->toDateTimeString(),
            'marketing_opted_in_at' => $request->validated('marketing_opt_in')
                ? now()->toDateTimeString()
                : null,
        ]);

        $loginResponse = $this->loginHandler->handle(new LoginCredentialsDTO(
            email: $email,
            password: $request->validated('password'),
            accountId: null,
        ));

        return $this->respondWithToken($loginResponse->token, new Collection());
    }
}
