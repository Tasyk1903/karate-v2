<?php

namespace App\Services\Students;

use App\Models\User;
use App\Services\Account\ProfileFieldValidation;
use App\Services\Account\ProfileFiles;
use App\Services\ProtectedMedia;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateStudentProfile
{
    public function update(User $coach, User $student, Request $request): User
    {
        $access = app(StudentProfileAccess::class);
        abort_unless($access->canUpdate($coach, $student), 403);
        $self = $access->isSelf($coach, $student);
        $rules = ['email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student->id)],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:300'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:250'],
            'rang' => ['sometimes', 'nullable', 'string', 'max:50'],
            'remove_documents' => ['sometimes', 'array', 'max:5'], 'remove_documents.*' => [Rule::in(ProtectedMedia::DOCUMENTS), 'distinct']];
        if ($self) {
            foreach (['first_name', 'last_name'] as $field) {
                $rules[$field] = ['sometimes', 'required', 'string', 'max:100'];
            }
            $rules['patronymic'] = ['sometimes', 'nullable', 'string', 'max:100'];
            $rules['gender'] = ['sometimes', 'required', Rule::in(['m', 'f'])];
            $rules['email'] = ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student->id)];
            $rules['avatar'] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'];
            $rules['remove_avatar'] = ['sometimes', 'boolean'];
        }
        foreach (['birthday', 'last_examination_date'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', function ($attribute, $value, $fail) {
                if (! ProfileFieldValidation::date($value)) {
                    $fail(__('mobile_profile.invalid_birthday'));
                }
            }];
        }
        foreach (['city_training', 'number_brand', 'number_iko', 'number_certificate', 'last_examination_city', 'last_receiving'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:255'];
        }
        foreach (ProtectedMedia::DOCUMENTS as $field) {
            $rules[$field] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=8192,max_height=8192'];
        }
        foreach (ProtectedMedia::DOCUMENTS as $field) {
            $rules['is_success_'.$field] = ['prohibited'];
            $rules['is_'.$field.'_included_check'] = ['prohibited'];
        }
        $rules['insurance_close_date'] = ['prohibited'];
        $data = $request->validate($rules);
        foreach (['birthday', 'last_examination_date'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = ProfileFieldValidation::date($data[$field]);
            }
        }
        $removed = $data['remove_documents'] ?? [];
        if ($self && $request->boolean('remove_avatar')) {
            $removed[] = 'avatar';
        }
        unset($data['remove_documents'], $data['remove_avatar']);
        $fileFields = $self ? [...ProtectedMedia::DOCUMENTS, 'avatar'] : ProtectedMedia::DOCUMENTS;
        $newFiles = [];
        $oldFiles = [];
        try {
            foreach ($fileFields as $field) {
                unset($data[$field]);
                if ($request->hasFile($field)) {
                    if (in_array($field, $removed, true)) {
                        throw ValidationException::withMessages([$field => __('mobile_students.file_conflict')]);
                    }
                    $newFiles[$field] = $request->file($field)->store($field.'/students', $field === 'avatar' ? 'public' : 'protected');
                }
            }

            return DB::transaction(function () use ($coach, $student, $data, $removed, $newFiles, &$oldFiles): User {
                $actor = User::query()->lockForUpdate()->findOrFail($coach->id);
                $student = User::query()->lockForUpdate()->findOrFail($student->id);
                $access = app(StudentProfileAccess::class);
                abort_unless($access->canUpdate($actor, $student), 403);
                $self = $access->isSelf($actor, $student);
                $capabilities = app(StudentProfileAccess::class)->capabilities($actor, $student, lock: true);
                foreach (['birthday', 'rang', 'weight'] as $field) {
                    if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $student->$field && ! $capabilities[$field]) {
                        throw ValidationException::withMessages([$field => __('mobile_profile.field_locked')]);
                    }
                }
                if (array_key_exists('rang', $data) && $data['rang'] !== $student->rang && ! ProfileFieldValidation::rank($data['rang'])) {
                    throw ValidationException::withMessages(['rang' => __('mobile_profile.invalid_rank')]);
                }
                $payload = $data;
                $setup = $self && app(StudentProfileSetup::class)->required($student);
                if ($setup) {
                    app(StudentProfileSetup::class)->validate($student, $payload);
                    $payload['student_profile_setup_required'] = false;
                }
                if ($self && array_intersect(array_keys($payload), ['first_name', 'last_name', 'birthday'])) {
                    $identity = array_replace($student->only(['first_name', 'last_name', 'birthday']), array_intersect_key($payload, array_flip(['first_name', 'last_name', 'birthday'])));
                    if ($student->coach_id && User::query()->where('coach_id', $student->coach_id)->where($identity)->whereKeyNot($student->id)->exists()) {
                        throw ValidationException::withMessages(['first_name' => __('student_account.duplicate_identity')]);
                    }
                    $payload['name'] = trim($identity['last_name'].' '.$identity['first_name']);
                }
                foreach (array_unique(array_merge(array_keys($newFiles), $removed)) as $field) {
                    $oldFiles[] = $student->$field;
                    if ($student->$field && $field !== 'avatar') {
                        // Keep legacy paths private even after the last database reference is removed.
                        app(ProtectedMedia::class)->migrateFile($student->$field);
                    }
                    $payload[$field] = $newFiles[$field] ?? null;
                    if ($field !== 'avatar') {
                        $payload['is_success_'.$field] = false;
                    }
                    if ($field === 'insurance') {
                        $payload['insurance_close_date'] = null;
                    }
                }
                $auditFields = [...array_keys($payload), 'competitive_record_starts_at'];
                $before = $student->only($auditFields);
                $student->forceFill($payload);
                app(CompetitiveRecordPeriod::class)->synchronize($student);
                $student->save();
                TeamActivity::record($actor, $self ? 'student.profile.updated' : 'mobile.student.updated', User::class, $student->id,
                    ['student_id' => $student->id, 'self' => $self, 'old' => $before, 'new' => $student->only($auditFields)]);
                if ($setup) {
                    TeamActivity::record($actor, 'student.profile_setup.completed', User::class, $student->id,
                        ['old' => ['student_profile_setup_required' => true], 'new' => ['student_profile_setup_required' => false]]);
                }

                return $student;
            }, 3);
        } catch (\Throwable $error) {
            foreach ($newFiles as $field => $path) {
                Storage::disk($field === 'avatar' ? 'public' : 'protected')->delete($path);
            }
            throw $error;
        } finally {
            // References still present after a rollback prevent deleting the old document.
            foreach (array_filter($oldFiles) as $path) {
                app(ProfileFiles::class)->deleteUnreferenced($path);
            }
        }
    }
}
