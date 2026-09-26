<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * One row per interest: `kind` is category|city, `value` is the slug.
 *
 * The table carries `created_at` but no `updated_at`, so Eloquent is told to
 * manage only the insert timestamp.
 *
 * @mixin Builder
 */
class UserInterest extends BaseModel
{
    public const UPDATED_AT = null;

    protected function getFillableFields(): array
    {
        return ['user_id', 'kind', 'value', 'created_at'];
    }
}
