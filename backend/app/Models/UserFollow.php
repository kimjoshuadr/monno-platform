<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * A buyer following an organizer's room. Replaces monno's localStorage follows.
 *
 * The table carries `created_at` but no `updated_at`, so Eloquent is told to
 * manage only the insert timestamp.
 *
 * @mixin Builder
 */
class UserFollow extends BaseModel
{
    public const UPDATED_AT = null;

    protected function getFillableFields(): array
    {
        return ['user_id', 'organizer_id', 'created_at'];
    }
}
