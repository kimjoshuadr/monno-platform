<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * A buyer saving an event for later. Replaces monno's localStorage bookmarks.
 *
 * The table carries `created_at` but no `updated_at`, so Eloquent is told to
 * manage only the insert timestamp.
 *
 * @mixin Builder
 */
class UserSavedEvent extends BaseModel
{
    public const UPDATED_AT = null;

    protected function getFillableFields(): array
    {
        return ['user_id', 'event_id', 'created_at'];
    }
}
