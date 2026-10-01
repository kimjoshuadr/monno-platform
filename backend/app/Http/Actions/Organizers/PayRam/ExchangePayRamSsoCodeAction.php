<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\PayRam;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\CreatePayRamSsoTokenHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Server-to-server redemption of a console SSO code, called by the PayRam app
 * (never by a browser). Guarded by a shared secret, not by a user session.
 *
 * The code is single-use: it is pulled from the cache, so a replayed request
 * finds nothing.
 */
class ExchangePayRamSsoCodeAction extends BaseAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.payram.sso_secret', '');
        $provided = (string) $request->header('X-PayRam-SSO-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return $this->jsonResponse(['message' => 'Unauthorized'], 401);
        }

        $code = (string) $request->input('code', '');
        if ($code === '') {
            return $this->jsonResponse(['message' => 'A code is required.'], 422);
        }

        $tokens = Cache::pull(CreatePayRamSsoTokenHandler::CACHE_PREFIX.$code);

        if (! is_array($tokens)) {
            return $this->jsonResponse(
                ['message' => 'This console link has expired. Please open it again from your settings.'],
                410,
            );
        }

        return $this->jsonResponse(['tokens' => $tokens]);
    }
}
