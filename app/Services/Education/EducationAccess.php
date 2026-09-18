<?php

namespace App\Services\Education;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EducationAccess
{
    public const SECTIONS = ['kata_attestation', 'kihon', 'ido_geiko', 'competition', 'works'];

    public function allowed(User $user, string $section): bool
    {
        if (! in_array($section, self::SECTIONS, true) || ! $user->hasAnyProjectRole(['Coach', 'Student'])) {
            return false;
        }
        $resource = match ($section) {
            'competition' => 'kata::competitions',
            'works' => 'education::klass::video',
            default => 'education::kata::category',
        };
        $roleIds = DB::table('model_has_roles')->where('model_type', User::class)
            ->where('model_id', $user->id)->pluck('role_id')->push($user->role_id)->filter();
        $names = DB::table('permissions')->where('guard_name', 'web')
            ->whereIn('name', ['view_any_'.$resource, 'view_'.$resource])
            ->where(function ($query) use ($user, $roleIds): void {
                $query->whereIn('id', DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->select('permission_id'))
                    ->orWhereIn('id', DB::table('model_has_permissions')->where('model_type', User::class)
                        ->where('model_id', $user->id)->select('permission_id'));
            })->pluck('name');

        return $names->contains('view_any_'.$resource) && $names->contains('view_'.$resource);
    }

    public function authorize(User $user, string $section): void
    {
        abort_unless($this->allowed($user, $section), 403);
    }
}
