<?php

namespace HiEvents\Http\Actions\Organizers\PayRam;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\SetupPayRamCryptoConnectHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SetupPayRamCryptoConnectAction extends BaseAction
{
    public function __construct(
        private readonly SetupPayRamCryptoConnectHandler $handler,
    ) {}

    public function __invoke(int $organizerId, Request $request): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class, Role::ADMIN);

        $validated = $request->validate([
            'wallet_address' => ['required', 'string'],
            'currencies' => ['nullable', 'array'],
            'currencies.*' => ['string'],
            'tron_wallet_address' => ['nullable', 'string'],
            'btc_wallet_address' => ['nullable', 'string'],
        ]);

        $result = $this->handler->handle(
            organizerId: $organizerId,
            walletAddress: $validated['wallet_address'],
            currencies: $validated['currencies'] ?? [],
            tronWalletAddress: $validated['tron_wallet_address'] ?? null,
            btcWalletAddress: $validated['btc_wallet_address'] ?? null,
        );

        return $this->jsonResponse($result);
    }
}
