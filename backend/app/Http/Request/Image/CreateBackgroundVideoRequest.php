<?php

namespace HiEvents\Http\Request\Image;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Validators\Rules\ValidBackgroundMediaRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBackgroundVideoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'video' => [
                'required',
                'file',
                app(ValidBackgroundMediaRule::class),
            ],
            'image_type' => [
                'required',
                Rule::in([ImageType::EVENT_BACKGROUND->name, ImageType::ORGANIZER_BACKGROUND->name]),
            ],
            'entity_id' => [
                'required',
                'integer',
            ],
        ];
    }
}
