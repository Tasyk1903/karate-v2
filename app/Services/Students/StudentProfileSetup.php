<?php

namespace App\Services\Students;

use App\Models\User;
use App\Services\Account\ProfileFieldValidation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class StudentProfileSetup
{
    public function required(User $user): bool
    {
        return (bool) $user->student_profile_setup_required && $user->projectRoleNames() === ['Student'];
    }

    public function validate(User $student, array $changes): void
    {
        Validator::make(array_replace($student->only([
            'first_name', 'last_name', 'birthday', 'gender', 'weight', 'rang', 'city_training',
        ]), $changes), [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthday' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! ProfileFieldValidation::date($value)) {
                    $fail(__('mobile_profile.invalid_birthday'));
                }
            }],
            'gender' => ['required', Rule::in(['m', 'f'])],
            'weight' => ['required', 'integer', 'min:1', 'max:300'],
            'rang' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! ProfileFieldValidation::rank($value)) {
                    $fail(__('mobile_profile.invalid_rank'));
                }
            }],
            'city_training' => ['required', 'string', 'max:255'],
        ])->validate();
    }
}
