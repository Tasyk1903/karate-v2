<?php

namespace App\Services;

use App\Models\User;

final class PanelAccess
{
    public static function teamSections(User $user): array
    {
        if ($user->hasProjectRole('Organization')) {
            return ['judges', 'secretaries', 'trainers', 'students', 'pending'];
        }

        return $user->hasProjectRole('Secretary') ? ['trainers', 'students', 'pending'] : [];
    }

    public static function canViewExaminations(User $user): bool
    {
        return $user->hasProjectRole('Organization')
            || $user->hasProjectRole('Coach')
            || $user->hasProjectRole('Student');
    }

    public static function capabilities(User $user): array
    {
        return [
            'super_admin' => $user->hasProjectRole('super_admin') && ! $user->is_external,
            'team_sections' => self::teamSections($user),
            'view_examinations' => self::canViewExaminations($user),
            'manage_examinations' => $user->hasProjectRole('Organization'),
        ];
    }
}
