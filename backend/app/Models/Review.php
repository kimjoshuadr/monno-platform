<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * A buyer's review of an event they attended.
 *
 * Queried through Eloquent directly rather than through the domain-object and
 * repository layers: the generator only emits objects for tables it already
 * knows, and this table is new and small. Same approach SetAccountContext and
 * UserRepository already take.
 *
 * @mixin Builder
 */
class Review extends BaseModel
{
    protected function getFillableFields(): array
    {
        return ['user_id', 'event_id', 'rating', 'body'];
    }

    protected function getCastMap(): array
    {
        return ['rating' => 'integer'];
    }
}
