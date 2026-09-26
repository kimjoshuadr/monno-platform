<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Profile;

use HiEvents\Http\Request\BaseRequest;

class ReviewRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'event_id' => ['required_without:review_id', 'integer', 'exists:events,id'],
            'review_id' => ['nullable', 'integer'],
            // Rule::between does not exist on this framework version.
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
