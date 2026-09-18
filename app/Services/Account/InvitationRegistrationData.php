<?php

namespace App\Services\Account;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InvitationRegistrationData
{
    public static function validate(Request $request, string $kind): array
    {
        return $request->validate([
            $kind === 'trainer' ? 'organization_code' : 'coach_code' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'existing_account' => ['required', 'boolean'],
            'password' => ['required', 'string', $request->boolean('existing_account') ? 'min:1' : 'min:8', 'max:255'],
            'password_confirmation' => [Rule::requiredIf(! $request->boolean('existing_account')), 'same:password'],
            'first_name' => [Rule::requiredIf(! $request->boolean('existing_account')), 'string', 'max:255'],
            'last_name' => [Rule::requiredIf(! $request->boolean('existing_account')), 'string', 'max:255'],
        ]);
    }
}
