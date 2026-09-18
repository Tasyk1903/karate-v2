<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProjectPermission
{
    public static function allows(User $user, string $permission): bool
    {
        $roles = DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->pluck('role_id')->push($user->role_id)->filter();

        return DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->where(function ($q) use ($roles, $user) {
            $q->whereIn('id', DB::table('role_has_permissions')->whereIn('role_id', $roles)->select('permission_id'))
                ->orWhereIn('id', DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $user->id)->select('permission_id'));
        })->exists();
    }
}
