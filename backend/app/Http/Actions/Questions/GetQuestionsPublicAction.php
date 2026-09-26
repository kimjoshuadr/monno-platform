<?php

namespace HiEvents\Http\Actions\Questions;

use HiEvents\DomainObjects\Generated\QuestionDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Http\Actions\Events\BasePublicEventAction;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Resources\Question\QuestionResourcePublic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetQuestionsPublicAction extends BasePublicEventAction
{
    public function __construct(
        private readonly QuestionRepositoryInterface $questionRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function __invoke(Request $request, int $eventId): Response|JsonResponse
    {
        $event = $this->eventRepository->findById($eventId);

        if (! $this->canUserViewEvent($event)) {
            return $this->notFoundResponse();
        }

        $questions = $this->questionRepository
            ->loadRelation(ProductDomainObject::class)
            ->findWhere([
                QuestionDomainObjectAbstract::EVENT_ID => $eventId,
                QuestionDomainObjectAbstract::IS_HIDDEN => false,
            ])
            ->sortBy(fn (QuestionDomainObjectAbstract $question) => $question->getOrder());

        return $this->resourceResponse(QuestionResourcePublic::class, $questions);
    }
}
