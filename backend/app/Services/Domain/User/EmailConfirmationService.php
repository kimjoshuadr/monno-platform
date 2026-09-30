<?php

namespace HiEvents\Services\Domain\User;

use Carbon\Carbon;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Mail\Account\ConfirmEmailAddressEmail;
use HiEvents\Mail\Account\EmailConfirmationCodeEmail;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Infrastructure\Encryption\EncryptedPayloadService;
use HiEvents\Services\Infrastructure\Encryption\Exception\DecryptionFailedException;
use HiEvents\Services\Infrastructure\Encryption\Exception\EncryptedPayloadExpiredException;
use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Throwable;

class EmailConfirmationService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly EncryptedPayloadService $encryptedPayloadService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly EmailVerificationCodeService $emailVerificationCodeService,
        private readonly VerifyUserEmailService $verifyUserEmailService,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @throws DecryptionFailedException
     * @throws EncryptedPayloadExpiredException|Throwable
     */
    public function confirmEmailAddress(string $token, int $accountId): void
    {
        $this->databaseManager->transaction(function () use ($accountId, $token) {
            ['id' => $userId] = $this->encryptedPayloadService->decryptPayload($token);

            $user = $this->userRepository->findByIdAndAccountId($userId, $accountId);

            $this->verifyUserEmailService->markEmailAsVerified($user, $accountId);
        });
    }

    /**
     * Send whichever confirmation matches the user's state.
     *
     * An unverified user gets the short-lived 5-digit code; anyone already
     * verified falls through to the long-lived link, which is what re-confirming
     * an account (or a pending email change) actually wants.
     */
    public function sendConfirmation(UserDomainObject $user, int $accountId): void
    {
        if ($this->confirmationCodeIsRequired($user, $accountId)) {
            $this->mailer
                ->to($user->getEmail())
                ->locale($user->getLocale())
                ->send(new EmailConfirmationCodeEmail(
                    $user,
                    $this->emailVerificationCodeService->storeAndReturnCode($user->getEmail()),
                ));

            return;
        }

        $token = $this->encryptedPayloadService->encryptPayload([
            'id' => $user->getId(),
        ], Carbon::now()->addMonths(6));

        $this->mailer
            ->to($user->getEmail())
            ->send(new ConfirmEmailAddressEmail($user, $token));
    }

    private function confirmationCodeIsRequired(UserDomainObject $user, int $accountId): bool
    {
        // Already verified: never burn a fresh code at them on resend.
        if ($user->getEmailVerifiedAt() !== null) {
            return false;
        }

        // Our gate: every unverified organizer, whether or not they have events.
        if ((bool) config('app.require_email_verification')) {
            return true;
        }

        // Original Hi.Events behaviour, preserved for the vendor flag: send a
        // code only to a first-time account with no events to its name.
        if (! (bool) config('app.enforce_email_confirmation_during_registration')) {
            return false;
        }

        return $this->eventRepository->findWhere([
            'account_id' => $accountId,
        ])->isEmpty();
    }
}
