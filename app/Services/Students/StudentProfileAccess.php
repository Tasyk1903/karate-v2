<?php

namespace App\Services\Students;

use App\Models\Tournament;
use App\Models\User;
use App\Services\Account\TournamentProfileAccess;
use App\Services\Team\JoinCoach;

final class StudentProfileAccess
{
    public function isSelf(User $actor, User $student): bool
    {
        return $actor->id === $student->id && $actor->projectRoleNames() === ['Student'];
    }

    public function canUpdate(User $actor, User $student): bool
    {
        return $this->isSelf($actor, $student) || $this->owns($actor, $student);
    }

    public function owns(User $coach, User $student): bool
    {
        return $coach->hasProjectRole('Coach') && $student->projectRoleNames() === ['Student']
            && (int) $student->coach_id === (int) $coach->id;
    }

    public function capabilities(User $coach, User $student, bool $lock = false): array
    {
        if ($this->isSelf($coach, $student)) {
            $setup = app(StudentProfileSetup::class)->required($student);
            $canEdit = app(TournamentProfileAccess::class)->canEdit($student, false, $lock);
            $participating = Tournament::query()->whereHas('championship')
                ->whereDate('date_finish', '>=', today())
                ->whereHas('students', fn ($query) => $query->where('users.id', $student->id))->exists();

            return ['edit' => true, 'detach' => false, 'documents' => true,
                'join_coach' => app(JoinCoach::class)->available($student),
                'birthday' => $canEdit || ($setup && ! $student->birthday),
                'rang' => $canEdit || ($setup && ! $student->rang),
                'weight' => ! $participating, 'delete_account' => $canEdit,
                'confirm_documents' => false];
        }
        $owns = $this->owns($coach, $student);
        $canEdit = $owns && app(TournamentProfileAccess::class)->canEdit($student, true, $lock);

        return ['edit' => $owns, 'detach' => $owns, 'documents' => $owns,
            'birthday' => $canEdit, 'rang' => $canEdit,
            'weight' => $owns,
            'confirm_documents' => false];
    }
}
