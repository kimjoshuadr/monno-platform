<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\UserSavedEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateMySavedEventAction extends BaseAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
        ]);

        // firstOrCreate: tapping save twice must not trip the unique index.
        $saved = UserSavedEvent::firstOrCreate([
            'user_id' => $this->getAuthenticatedUser()->getId(),
            'event_id' => (int) $validated['event_id'],
        ]);

        return $this->jsonResponse(
            ['data' => ['event_id' => (int) $saved->event_id, 'saved_at' => $saved->created_at]],
            201
        );
    }
}
