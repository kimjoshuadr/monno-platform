<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Auth;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Locale;
use HiEvents\Validators\Rules\RulesHelper;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Ticket-buyer sign up: a person who wants to register for events, not an
 * organizer opening an account — so no currency, and no invite token.
 */
class RegisterBuyerRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'first_name' => RulesHelper::REQUIRED_STRING,
            'last_name' => RulesHelper::STRING,
            'email' => RulesHelper::REQUIRED_EMAIL,
            'password' => ['required', 'confirmed', Password::min(8)],
            'timezone' => ['nullable', 'timezone:all'],
            'locale' => ['nullable', Rule::in(Locale::getSupportedLocales())],
            'marketing_opt_in' => 'boolean|nullable',
        ];
    }
}
