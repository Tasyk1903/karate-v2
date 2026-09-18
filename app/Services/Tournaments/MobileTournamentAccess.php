<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use App\Models\User;
use App\Services\Students\StudentProfileAccess;

final class MobileTournamentAccess
{
    public function assigned(?User $actor, Tournament $tournament): bool
    {
        if (! $actor || $actor->is_external) {
            return false;
        }

        return $actor->projectRoleNames() === ['Student']
            ? app(StudentTournamentEnrollment::class)->admitted($actor, $tournament)
            : app(CoachTournamentAccess::class)->assigned($actor, $tournament);
    }

    public function manages(User $actor, Tournament $tournament): bool
    {
        return $actor->projectRoleNames() === ['Student']
            ? app(StudentTournamentEnrollment::class)->canManage($actor, $tournament)
            : app(CoachTournamentAccess::class)->canManage($actor, $tournament);
    }

    public function owns(User $actor, User $student): bool
    {
        return app(StudentProfileAccess::class)->canUpdate($actor, $student);
    }

    public function canPay(User $payer, User $student, Tournament $tournament): bool
    {
        return ! $payer->is_external && $this->owns($payer, $student)
            && $this->manages($payer, $tournament) && app(CoachTournamentAccess::class)->online($tournament)
            && app(CoachTournamentAccess::class)->rankAllowed($student, $tournament);
    }
}
