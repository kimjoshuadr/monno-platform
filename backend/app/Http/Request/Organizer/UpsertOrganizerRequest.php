<?php

namespace HiEvents\Http\Request\Organizer;

use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class UpsertOrganizerRequest extends BaseRequest
{
    public function rules(): array
    {
        $currencies = include __DIR__.'/../../../../data/currencies.php';

        return [
            'name' => ['required', 'string', 'max:100'],
            // Optional handle for the public /o/{handle} URL. When absent the
            // slug stays derived from the name.
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'email' => ['email', 'required'],
            'phone' => ['string', 'nullable', 'max:25'],
            'website' => ['url', 'nullable', 'max:255'],
            'description' => ['string', 'nullable', 'max:1200'],
            'timezone' => ['timezone', 'required'],
            'currency' => ['required', Rule::in(array_values($currencies))],
        ];
    }
}
