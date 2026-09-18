<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Team\TeamActivity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UpdateCoachProfile
{
    public function update(User $actor, Request $request): User
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'patronymic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($actor->id)],
            'gender' => ['required', Rule::in(['m', 'f'])],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:50', 'max:260'],
            'birthday' => ['sometimes', 'nullable', 'string', function ($attribute, $value, $fail): void {
                if (! $this->birthday($value)) {
                    $fail(__('mobile_profile.invalid_birthday'));
                }
            }],
            'rang' => ['sometimes', 'nullable', 'string', 'max:50'],
            'club' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city_training' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ]);
        if (isset($data['birthday'])) {
            $data['birthday'] = $this->birthday($data['birthday']);
        }
        unset($data['avatar']);
        $path = $request->file('avatar')?->store('avatar/mobile-profile', 'public');
        $oldAvatar = null;
        try {
            $user = DB::transaction(function () use ($actor, $data, $path, &$oldAvatar): User {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                $capabilities = app(CoachProfileAccess::class)->capabilities($user, lock: true);
                foreach (['birthday', 'rang', 'weight'] as $field) {
                    $old = $field === 'birthday' && $user->birthday ? Carbon::parse($user->birthday)->toDateString() : $user->$field;
                    if (array_key_exists($field, $data) && (string) $old !== (string) $data[$field] && ! $capabilities[$field]) {
                        throw ValidationException::withMessages([$field => __('mobile_profile.field_locked')]);
                    }
                }
                if (array_key_exists('rang', $data) && $data['rang'] !== $user->rang &&
                    ! ProfileFieldValidation::rank($data['rang'])) {
                    throw ValidationException::withMessages(['rang' => __('mobile_profile.invalid_rank')]);
                }
                $oldAvatar = $user->avatar;
                $before = $user->only(array_merge(array_keys($data), ['avatar']));
                $user->forceFill($data + ($path ? ['avatar' => $path] : []));
                $user->name = trim($user->last_name.' '.$user->first_name);
                $user->save();
                TeamActivity::record($user, 'mobile.trainer.profile.updated', User::class, $user->id,
                    ['old' => $before, 'new' => $user->only(array_keys($before)), 'self' => true]);

                return $user;
            }, 3);
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $error;
        }
        if ($path && $oldAvatar && str_starts_with($oldAvatar, 'avatar/mobile-profile/') &&
            ! User::withTrashed()->where('avatar', $oldAvatar)->exists()) {
            Storage::disk('public')->delete($oldAvatar);
        }

        return $user;
    }

    private function birthday(string $value): ?string
    {
        return ProfileFieldValidation::date($value);
    }
}
