<?php

namespace HiEvents\Http\Actions\PromoCodes;

use HiEvents\DomainObjects\Generated\PromoCodeDomainObjectAbstract;
use HiEvents\Http\Actions\Events\BasePublicEventAction;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Domain\PromoCode\PromoCodeUsageValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetPromoCodePublic extends BasePublicEventAction
{
    public function __construct(
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly PromoCodeUsageValidationService $promoCodeUsageValidationService,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function __invoke(int $eventId, string $promoCode, Request $request): Response|JsonResponse
    {
        $event = $this->eventRepository->findById($eventId);

        if (! $this->canUserViewEvent($event)) {
            return $this->notFoundResponse();
        }

        $promoCode = $this->promoCodeRepository->findFirstWhere([
            PromoCodeDomainObjectAbstract::CODE => strtolower(trim($promoCode)),
            PromoCodeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        $isUsable = $this->promoCodeUsageValidationService->isPromoCodeUsable($promoCode);

        if (! $isUsable) {
            return $this->jsonResponse([
                'valid' => false,
            ]);
        }

        return $this->jsonResponse([
            'valid' => true,
            'discount' => $promoCode->getDiscount(),
            'discount_type' => $promoCode->getDiscountType(),
            'discount_applies_to' => $promoCode->getDiscountAppliesTo(),
            'applies_to_all_products' => empty($promoCode->getApplicableProductIds()),
        ]);
    }
}
