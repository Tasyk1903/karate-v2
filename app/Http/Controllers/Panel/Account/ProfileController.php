<?php

namespace App\Http\Controllers\Panel\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountAccess;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        AccountAccess::authorize($request->user());

        return response()->json($this->payload($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        AccountAccess::authorize($user);
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
        $isOrganization = $user->hasProjectRole('Organization');
        $rules = $isOrganization ? ['name' => ['required', 'string', 'max:255']] : [
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
        ];
        $data = $request->validate($rules + ['avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'], 'remove_avatar' => ['sometimes', 'boolean']]);
        $path = $request->file('avatar')?->store('avatar/profile', 'public');
        $oldAvatar = null;
        try {
            DB::transaction(function () use ($user, $data, $rules, $path, $request, $isOrganization, &$oldAvatar): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $before = $locked->only(['name', 'first_name', 'last_name', 'avatar']);
                $oldAvatar = $locked->avatar;
                $fields = array_intersect_key($data, $rules);
                if (! $isOrganization) {
                    $fields['name'] = trim($fields['last_name'].' '.$fields['first_name']);
                }
                if ($path || $request->boolean('remove_avatar')) {
                    $fields['avatar'] = $path;
                }
                $locked->forceFill($fields)->save();
                TeamActivity::record($locked, 'profile.updated', User::class, $locked->id, ['old' => $before, 'new' => $locked->only(array_keys($before))]);
            });
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $error;
        }
        // Only remove uploads owned by this workflow; legacy avatars may be shared.
        if (($path || $request->boolean('remove_avatar')) && $oldAvatar && preg_match('~^avatar/profile/[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$~', $oldAvatar)
            && ! User::withTrashed()->where('avatar', $oldAvatar)->exists()) {
            Storage::disk('public')->delete($oldAvatar);
        }

        return response()->json($this->payload($user->fresh()));
    }

    private function payload(User $user): array
    {
        return $user->only(['name', 'first_name', 'last_name']) + ['is_organization' => $user->hasProjectRole('Organization'), 'avatar' => $user->avatar ? '/storage/'.ltrim($user->avatar, '/') : null];
    }
}
