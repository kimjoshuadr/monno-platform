<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Profile;

use HiEvents\Http\Request\BaseRequest;

/**
 * The public identity shown on a buyer's profile. All three are written
 * together — there is no partial patch here, so an absent key and a cleared
 * value cannot be confused.
 */
class ProfileRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
        ];
    }
}
