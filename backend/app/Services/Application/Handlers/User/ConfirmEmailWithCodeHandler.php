<?php

namespace HiEvents\Services\Application\Handlers\User;

use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\User\DTO\ConfirmEmailWithCodeDTO;
use HiEvents\Services\Application\Handlers\User\Exception\InvalidEmailVerificationCodeException;
use HiEvents\Services\Domain\User\VerifyUserEmailService;
use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Illuminate\Database\DatabaseManager;

class ConfirmEmailWithCodeHandler
{
    public function __construct(
        private readonly EmailVerificationCodeService $emailVerificationCodeService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly VerifyUserEmailService $verifyUserEmailService,
    ) {}

    /**
     * @throws InvalidEmailVerificationCodeException
     */
    public function handle(ConfirmEmailWithCodeDTO $dto): void
    {
        $this->databaseManager->transaction(function () use ($dto) {
            $user = $this->userRepository->findByIdAndAccountId($dto->userId, $dto->accountId);

            // Idempotent on purpose: the code is auto-submitted after a debounce,
            // so a verification finished in another tab (or retried after a slow
            // response) must read as success rather than a red conflict error.
            if ($user->getEmailVerifiedAt() !== null) {
                return;
            }

            $outcome = $this->emailVerificationCodeService->attempt($user->getEmail(), $dto->code);

            if ($outcome === EmailVerificationCodeService::OUTCOME_OK) {
                $this->verifyUserEmailService->markEmailAsVerified($user, $dto->accountId);

                return;
            }

            throw new InvalidEmailVerificationCodeException(match ($outcome) {
                EmailVerificationCodeService::OUTCOME_EXHAUSTED => __(
                    'That code has been used too many times. Request a new one to continue.',
                ),
                EmailVerificationCodeService::OUTCOME_EXPIRED => __(
                    'This code has expired. Request a new one and we\'ll email it to you.',
                ),
                default => __('The verification code is invalid or has expired.'),
            });
        });
    }
}
