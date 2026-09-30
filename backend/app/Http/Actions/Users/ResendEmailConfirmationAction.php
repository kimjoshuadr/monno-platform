<?php

namespace HiEvents\Http\Actions\Users;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\User\ResendEmailConfirmationHandler;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ResendEmailConfirmationAction extends BaseAction
{
    private const COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly ResendEmailConfirmationHandler $resendEmailConfirmationHandler,
    ) {}

    /**
     * Two ceilings, because neither alone is enough:
     *
     *  - a 30-second cooldown stops the obvious hammering, and
     *  - a rolling daily quota stops a patient loop from mailing hundreds of
     *    codes to one address, which would burn the sending domain's reputation
     *    and give an attacker far more tries at the 5-digit code than the
     *    verify endpoint's own attempt budget allows.
     *
     * @throws TooManyRequestsHttpException
     */
    public function __invoke(int $userId): Response
    {
        $user = $this->getAuthenticatedUser();
        $cooldownKey = 'resend_email_confirmation:'.$user->getId();
        $dailyKey = 'resend_email_confirmation_daily:'.$user->getId();

        $dailyLimit = max(1, (int) config('app.email_verification_resend_daily_limit', 10));
        $sentToday = (int) Cache::get($dailyKey, 0);

        if ($sentToday >= $dailyLimit) {
            throw new TooManyRequestsHttpException(3600, __(
                'You have already requested :limit codes. Wait a while and try again.', [
                    'limit' => $dailyLimit,
                ]));
        }

        // Check if user has requested a resend within the last 30 seconds
        if (Cache::has($cooldownKey)) {
            $remainingSeconds = (int) (Cache::get($cooldownKey) - now()->timestamp);
            throw new TooManyRequestsHttpException($remainingSeconds, __(
                'Please wait :seconds seconds before requesting another code.', [
                    'seconds' => $remainingSeconds,
                ]));
        }

        // Set the cooldown for 30 seconds
        Cache::put($cooldownKey, now()->addSeconds(self::COOLDOWN_SECONDS)->timestamp, self::COOLDOWN_SECONDS);

        $this->resendEmailConfirmationHandler->handle($user, $this->getAuthenticatedAccountId());

        // Counted only once the mail actually went out, so a failed send never
        // eats into the quota the organizer needs to recover with.
        Cache::put($dailyKey, $sentToday + 1, now()->addDay());

        return $this->noContentResponse();
    }
}
