<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Education\EducationAccess;
use App\Services\Examinations\StudentExaminationEnrollment;

final class MobileAppAccess
{
    public function role(User $user): ?string
    {
        if ($user->is_external) {
            return null;
        }
        $roles = $user->projectRoleNames();

        // Staff roles never inherit the wider Coach navigation through a mixed account.
        if (array_intersect($roles, ['Judge', 'Master'])) {
            return count($roles) === 1 ? $roles[0] : null;
        }

        return in_array('Coach', $roles, true) ? 'Coach' : ($roles === ['Student'] ? 'Student' : null);
    }

    public function navigation(User $user): array
    {
        if ($this->role($user) === 'Judge') {
            return ['bottom' => ['judging', 'profile'], 'menu' => ['agreements', 'logout']];
        }
        if ($this->role($user) === 'Master') {
            return ['bottom' => ['reviews', 'rating', 'profile'], 'menu' => ['about', 'agreements', 'logout']];
        }
        $coach = $this->role($user) === 'Coach';
        $menu = $coach ? ['students', 'tournaments', 'quick_fights', 'exams'] : ['tournaments'];
        if (! $coach && app(StudentExaminationEnrollment::class)->allowed($user)) {
            $menu[] = 'exams';
        }
        if (collect(EducationAccess::SECTIONS)
            ->contains(fn ($section) => app(EducationAccess::class)->allowed($user, $section))) {
            $menu[] = 'education';
        }

        return ['bottom' => ['rating', 'feed', 'profile'], 'menu' => [...$menu, 'agreements', ...($coach ? ['settings'] : []), 'about', 'logout']];
    }
}
