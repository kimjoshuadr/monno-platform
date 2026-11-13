<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Http\Actions\Events\BasePublicEventAction;
use HiEvents\Http\Request\Order\CreateOrderRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Order\OrderResourcePublic;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CreateOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateOrderPublicDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Application\Locale\LocaleService;
use HiEvents\Services\Domain\Event\ExternalRegistrationService;
use HiEvents\Services\Domain\Order\OrderCreateRequestValidationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Http\JsonResponse;
use Throwable;

class CreateOrderActionPublic extends BasePublicEventAction
{
    public function __construct(
        private readonly CreateOrderHandler $orderHandler,
        private readonly OrderCreateRequestValidationService $orderCreateRequestValidationService,
        private readonly CheckoutSessionManagementService $sessionIdentifierService,
        private readonly LocaleService $localeService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ExternalRegistrationService $externalRegistrationService,
    ) {}

    /**
     * Create Order
     *
     * `products.*.event_occurrence_id` is required for recurring events; for single-occurrence
     * events it is inferred when omitted. Product availability, capacity, and pricing are
     * validated against the event's live configuration.
     *
     * @throws Throwable
     */
    public function __invoke(CreateOrderRequest $request, int $eventId): JsonResponse
    {
        $externalSettings = $this->externalRegistrationService->getEventSettings($eventId);

        if ($externalSettings?->isTicketingExternal()) {
            return $this->externalTicketingResponse($externalSettings);
        }

        $validatedData = $this->orderCreateRequestValidationService->validateRequestData($eventId, $request->all());
        $sessionId = $this->sessionIdentifierService->getSessionId();

        $order = $this->orderHandler->handle(
            eventId: $eventId,
            createOrderPublicDTO: CreateOrderPublicDTO::fromArray([
                'is_user_authenticated' => $this->isUserAuthenticated(),
                'promo_code' => $request->input('promo_code'),
                'affiliate_code' => $request->input('affiliate_code'),
                'products' => ProductOrderDetailsDTO::collectionFromArray($validatedData['products']),
                'session_identifier' => $sessionId,
                'order_locale' => $this->localeService->getLocaleOrDefault($request->getPreferredLanguage()),
            ])
        );

        $order->setSessionIdentifier($sessionId);

        // A signed-in buyer's order must know who placed it, or the ticket
        // never turns up on their profile. Done here rather than inside the
        // handler to keep the checkout path untouched; the email backfill in
        // the user_id migration covers anything this misses.
        if ($this->isUserAuthenticated()) {
            $this->orderRepository->updateWhere(
                ['user_id' => $this->getAuthenticatedUser()->getId()],
                ['id' => $order->getId()],
            );
        }

        $response = $this->resourceResponse(
            resource: OrderResourcePublic::class,
            data: $order,
            statusCode: ResponseCodes::HTTP_CREATED,
        );

        return $response->withCookie(
            cookie: $this->sessionIdentifierService->getSessionCookie(),
        );
    }
}
