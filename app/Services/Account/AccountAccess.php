<?php

namespace App\Services\Account;

use App\Models\User;

final class AccountAccess
{
    public static function authorizeReader(User $user): void
    {
        abort_unless($user->hasAnyProjectRole(['Organization', 'Secretary', 'Student']), 403);
    }

    public static function authorize(User $user): void
    {
        abort_unless($user->hasAnyProjectRole(['Organization', 'Secretary']), 403);
    }

    public static function organizationId(User $user): int
    {
        self::authorize($user);
        $id = $user->hasProjectRole('Organization') ? $user->id : $user->organization_id;
        abort_unless($id, 403);

        return (int) $id;
    }
}
