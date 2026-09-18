<?php

namespace App\Services\Tournaments;

use App\Models\ExternalForm;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExternalParticipantIdentity
{
    private array $coaches = [];

    public function resolve(ExternalForm $form, array $row, User $actor, bool $sync): array
    {
        $organization = (int) $form->championship->organization_id;
        $link = DB::table('external_form_students')->where('external_form_id', $form->id)->where('row_id', $row['row_id'])->first();
        $identity = $this->identityKey($row);
        if (! $link && $identity) {
            $link = DB::table('external_form_students')->where('external_form_id', $form->id)->where('identity_key', $identity)->first();
        }
        $user = $link ? User::query()->lockForUpdate()->find($link->user_id) : null;
        if ($link) {
            abort_unless($user && $user->hasProjectRole('Student') && array_diff($user->projectRoleNames(), ['Student']) === []
                && (int) $user->organization_id === $organization
                && (! $user->coach_id || (int) $user->coach?->organization_id === $organization), 403);
        }
        $fields = $this->profileFields($row);
        $created = ! $user;
        if ($created) {
            $coach = $this->coach($form, $row, $actor);
            $user = $this->createUser('Student', $fields + ['coach_id' => $coach->id, 'organization_id' => $organization]);
            TeamActivity::record($actor, 'external_form.student.created', User::class, $user->id,
                ['form_id' => $form->id, 'row_id' => $row['row_id'], 'old' => null, 'new' => $user->only(array_merge(array_keys($fields), ['coach_id', 'organization_id']))]);
        } elseif (! $user->coach_id) {
            if (! $user->is_external && ($user->email !== 'karaterating'.$user->id.'@karaterating.ru' || $user->email_verified_at)) {
                throw ValidationException::withMessages(['profile' => 'profile_protected']);
            }
            // Only a server-linked, owned student from an earlier import can be adopted.
            $coach = $this->coach($form, $row, $actor);
            $user->forceFill(['coach_id' => $coach->id, 'is_external' => true])->save();
            TeamActivity::record($actor, 'external_form.student.coach_assigned', User::class, $user->id,
                ['form_id' => $form->id, 'old' => ['coach_id' => null], 'new' => ['coach_id' => $coach->id]]);
        }
        $user->loadMissing('coach');
        $changed = collect($fields)->contains(fn ($value, $key) => (string) ($user->getRawOriginal($key) ?? '') !== (string) ($value ?? ''))
            || (string) $user->coach?->club !== (string) ($row['club'] ?? '')
            || (! empty($row['coach_first_name']) && $user->coach?->first_name !== $row['coach_first_name'])
            || (! empty($row['coach_last_name']) && $user->coach?->last_name !== $row['coach_last_name']);
        if (! $created && $changed && $sync) {
            if (! $user->is_external || ! $user->coach?->is_external) {
                throw ValidationException::withMessages(['profile' => 'profile_protected']);
            }
            $before = $user->only(array_merge(array_keys($fields), ['coach_id']));
            $coach = $this->coach($form, $row, $actor);
            $user->forceFill($fields + ['coach_id' => $coach->id])->save();
            $user->setRelation('coach', $coach);
            TeamActivity::record($actor, 'external_form.student.updated', User::class, $user->id,
                ['form_id' => $form->id, 'row_id' => $row['row_id'], 'old' => $before, 'new' => $user->only(array_keys($before))]);
        }
        $where = ['external_form_id' => $form->id, 'row_id' => $row['row_id']];
        $newLink = ! DB::table('external_form_students')->where($where)->exists();
        DB::table('external_form_students')->updateOrInsert($where, ['user_id' => $user->id, 'identity_key' => $identity,
            'profile_snapshot' => json_encode($fields, JSON_THROW_ON_ERROR), 'updated_at' => now()] + ($newLink ? ['created_at' => now()] : []));
        if ($newLink) {
            TeamActivity::record($actor, 'external_form.student.linked', User::class, $user->id,
                ['form_id' => $form->id, 'row_id' => $row['row_id'], 'old' => null, 'new' => ['user_id' => $user->id], 'match' => $created ? 'created' : 'same_form_identity']);
        }

        return ['user' => $user, 'created' => $created, 'profile_pending' => ! $created && $changed && ! $sync];
    }

    public function identityKey(array $row): ?string
    {
        if (empty($row['birthday']) || empty($row['first_name']) || empty($row['last_name'])) {
            return null;
        }

        return $this->key([$row['first_name'], $row['last_name'], $row['birthday'], $row['club'] ?? '', $row['coach_first_name'] ?? '', $row['coach_last_name'] ?? '']);
    }

    public function profileFields(array $row): array
    {
        return ['first_name' => $row['first_name'], 'last_name' => $row['last_name'],
            'name' => trim($row['last_name'].' '.$row['first_name']), 'birthday' => ! empty($row['birthday']) ? Carbon::createFromFormat('d.m.Y', $row['birthday'])->format('Y-m-d') : null,
            'weight' => $row['weight'] ?? null, 'age' => $row['age'] ?? null, 'gender' => $row['gender'] ?? null,
            'rang' => $row['rank'] ?? null, 'razriad' => $row['razriad'] ?? null];
    }

    private function coach(ExternalForm $form, array $row, User $actor): User
    {
        $key = $this->key([$row['coach_first_name'] ?? '', $row['coach_last_name'] ?? '', $row['club'] ?? '']);
        $cacheKey = $form->id.':'.$key;
        if (isset($this->coaches[$cacheKey]) && User::whereKey($this->coaches[$cacheKey]->id)->exists()) {
            return $this->coaches[$cacheKey];
        }
        $id = DB::table('external_form_coaches')->where('external_form_id', $form->id)->where('identity_key', $key)->value('user_id');
        $coach = $id ? User::findOrFail($id) : null;
        if ($coach) {
            abort_unless($coach->is_external && $coach->hasProjectRole('Coach') && (int) $coach->organization_id === (int) $form->championship->organization_id, 403);
        }
        if (! $coach) {
            $first = $row['coach_first_name'] ?? '';
            $last = $row['coach_last_name'] ?? '';
            if (! $first && ! $last) {
                $first = $form->organization_name;
            }
            $coach = $this->createUser('Coach', ['first_name' => $first, 'last_name' => $last, 'name' => trim($last.' '.$first),
                'club' => $row['club'] ?? null, 'organization_id' => $form->championship->organization_id]);
            DB::table('external_form_coaches')->insert(['external_form_id' => $form->id, 'identity_key' => $key, 'user_id' => $coach->id, 'created_at' => now(), 'updated_at' => now()]);
            TeamActivity::record($actor, 'external_form.coach.created', User::class, $coach->id,
                ['form_id' => $form->id, 'old' => null, 'new' => $coach->only(['name', 'club', 'organization_id', 'is_external'])]);
        }

        return $this->coaches[$cacheKey] = $coach;
    }

    private function createUser(string $role, array $fields): User
    {
        $user = new User;
        $user->forceFill($fields + ['email' => Str::uuid().'@external.invalid', 'password' => Str::random(64), 'is_external' => true])->save();
        DB::table('model_has_roles')->insert(['role_id' => DB::table('roles')->where('name', $role)->value('id') ?? throw new \RuntimeException('Missing role '.$role), 'model_type' => User::class, 'model_id' => $user->id]);

        return $user;
    }

    private function key(array $parts): string
    {
        return hash('sha256', json_encode(array_map(fn ($value) => mb_strtolower(trim((string) $value)), $parts), JSON_THROW_ON_ERROR));
    }
}
