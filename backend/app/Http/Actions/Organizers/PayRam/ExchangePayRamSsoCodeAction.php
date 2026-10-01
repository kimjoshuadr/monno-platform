<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\PayRam;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\CreatePayRamSsoTokenHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Redeems a one-time console SSO code for a PayRam session.
 *
 * Called from the gateway's own origin (a small page we ship in the PayRam
 * image), so it cannot require a shared secret — a browser page has nowhere to
 * keep one. The code is the credential: 64 random characters, single-use, and
 * short-lived, and it only ever travels in a URL fragment that is not sent to
 * any server.
 */
class ExchangePayRamSsoCodeAction extends BaseAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $code = (string) $request->input('code', '');

        if ($code === '') {
            return $this->jsonResponse(['message' => 'A code is required.'], 422);
        }

        $session = Cache::pull(CreatePayRamSsoTokenHandler::CACHE_PREFIX.$code);

        if (! is_array($session)) {
            return $this->jsonResponse(
                ['message' => 'This console link has expired. Please open it again from your settings.'],
                410,
            );
        }

        // Monno has already authenticated the organizer. The gateway's
        // first-login password reset would only send them in a circle (set a
        // password, then sign in again), so it is cleared here.
        $session['resetPasswordRequired'] = false;

        return $this->jsonResponse(['session' => $session]);
    }
}
