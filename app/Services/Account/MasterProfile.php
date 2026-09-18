<?php

namespace App\Services\Account;

use App\Models\EducationKlassVideo;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MasterProfile
{
    public const TEXT = ['first_name', 'last_name', 'patronymic', 'email', 'gender', 'birthday', 'rang', 'weight',
        'city_training', 'number_brand', 'number_iko', 'number_certificate', 'last_examination_date', 'last_examination_city', 'last_receiving'];

    public function capabilities(User $user): array
    {
        $master = $user->projectRoleNames() === ['Master'] && ! $user->is_external;
        $participating = Tournament::whereDate('date_finish', '>=', today())->whereHas('championship')
            ->whereHas('students', fn ($q) => $q->where('users.id', $user->id))->exists();
        $pending = EducationKlassVideo::where('reviewer_id', $user->id)->where('is_payment', true)->where('is_review', false)->exists();

        return ['edit' => $master, 'weight' => $master && ! $participating, 'delete_account' => $master && ! $pending];
    }

    public function format(User $user): array
    {
        $capabilities = $this->capabilities($user);
        $data = ['id' => $user->id, 'name' => $user->full_name, 'email' => $user->email,
            'role' => app(MobileAppAccess::class)->role($user), 'position' => $user->judge_position,
            'avatar_url' => $user->avatar ? asset('storage/'.$user->avatar) : null, 'capabilities' => $capabilities];
        if ($capabilities['edit']) {
            $data['fields'] = $user->only(self::TEXT);
            $data['documents'] = collect(ProtectedMedia::DOCUMENTS)->mapWithKeys(fn ($field) => [$field => app(ProtectedMedia::class)->documentUrl($user, $field, true)]);
        }

        return $data;
    }

    public function update(User $actor, Request $request): User
    {
        abort_unless($this->capabilities($actor)['edit'], 403);
        $rules = ['remove_documents' => 'sometimes|array|max:5', 'remove_documents.*' => ['distinct', Rule::in(ProtectedMedia::DOCUMENTS)],
            'remove_avatar' => 'sometimes|boolean'];
        foreach (self::TEXT as $field) {
            $rules[$field] = 'sometimes|nullable|string|max:255';
        }
        foreach (['first_name', 'last_name'] as $field) {
            $rules[$field] = 'sometimes|required|string|max:100';
        }
        $rules['email'] = ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($actor->id)];
        $rules['gender'] = 'sometimes|required|in:m,f';
        $rules['weight'] = 'sometimes|nullable|integer|min:1|max:300';
        foreach (['birthday', 'last_examination_date'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', function ($attribute, $value, $fail) {
                if (! ProfileFieldValidation::date($value)) {
                    $fail(__('mobile_profile.invalid_birthday'));
                }
            }];
        }
        foreach ([...ProtectedMedia::DOCUMENTS, 'avatar'] as $field) {
            $rules[$field] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=8192,max_height=8192'];
        }
        foreach (['role_id', 'roles', 'organization_id', 'club', 'judge_position', 'push_enabled'] as $field) {
            $rules[$field] = 'prohibited';
        }
        foreach (ProtectedMedia::DOCUMENTS as $field) {
            $rules['is_success_'.$field] = 'prohibited';
        }
        $data = $request->validate($rules);
        foreach (['birthday', 'last_examination_date'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = ProfileFieldValidation::date($data[$field]);
            }
        }
        $removed = $data['remove_documents'] ?? [];
        if ($request->boolean('remove_avatar')) {
            $removed[] = 'avatar';
        }
        $new = $old = [];
        try {
            foreach ([...ProtectedMedia::DOCUMENTS, 'avatar'] as $field) {
                if ($request->hasFile($field)) {
                    if (in_array($field, $removed, true)) {
                        throw ValidationException::withMessages([$field => __('mobile_students.file_conflict')]);
                    }
                    $new[$field] = $request->file($field)->store($field.'/masters', $field === 'avatar' ? 'public' : 'protected');
                }
            }
            $user = DB::transaction(function () use ($actor, $data, $removed, $new, &$old) {
                $user = User::lockForUpdate()->findOrFail($actor->id);
                $caps = $this->capabilities($user);
                abort_unless($caps['edit'], 403);
                $payload = array_intersect_key($data, array_flip(self::TEXT));
                if (array_key_exists('weight', $payload) && (string) $payload['weight'] !== (string) $user->weight && ! $caps['weight']) {
                    throw ValidationException::withMessages(['weight' => __('mobile_profile.field_locked')]);
                }
                if (array_key_exists('rang', $payload) && $payload['rang'] !== $user->rang && ! ProfileFieldValidation::rank($payload['rang'])) {
                    throw ValidationException::withMessages(['rang' => __('mobile_profile.invalid_rank')]);
                }
                foreach (array_unique([...array_keys($new), ...$removed]) as $field) {
                    $old[] = $user->$field;
                    if ($user->$field && $field !== 'avatar') {
                        app(ProtectedMedia::class)->migrateFile($user->$field);
                    }
                    $payload[$field] = $new[$field] ?? null;
                    if ($field !== 'avatar') {
                        $payload['is_success_'.$field] = false;
                    }
                    if ($field === 'insurance') {
                        $payload['insurance_close_date'] = null;
                    }
                }
                $before = $user->only(array_keys($payload));
                $user->forceFill($payload);
                $user->name = trim($user->last_name.' '.$user->first_name);
                $user->save();
                TeamActivity::record($user, 'master.profile.updated', User::class, $user->id,
                    ['self' => true, 'old' => $before, 'new' => $user->only(array_keys($before))]);

                return $user;
            }, 3);
        } catch (\Throwable $error) {
            foreach ($new as $field => $path) {
                Storage::disk($field === 'avatar' ? 'public' : 'protected')->delete($path);
            }
            throw $error;
        }
        foreach (array_filter($old) as $path) {
            app(ProfileFiles::class)->deleteUnreferenced($path);
        }

        return $user;
    }
}
