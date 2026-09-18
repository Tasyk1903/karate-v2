<?php

namespace App\Services\Tournaments\Kata;

use App\Models\KataPool;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\CoachTournamentAccess;
use App\Services\Tournaments\StudentTournamentEnrollment;
use App\Services\Tournaments\TournamentLifecycle;

final class KataVideoAccess
{
    public function canView(User $actor, Tournament $tournament, ?User $student, ?bool $assigned = null): bool
    {
        if ($actor->hasProjectRole('Student')) {
            return $student && (int) $student->id === (int) $actor->id
                && app(StudentTournamentEnrollment::class)->admitted($actor, $tournament);
        }

        return $student && $student->hasProjectRole('Student') && (int) $student->coach_id === (int) $actor->id
            && ($assigned ?? app(CoachTournamentAccess::class)->assigned($actor, $tournament));
    }

    public function application(KataPool $pool, int $studentId): ?StudentTournament
    {
        if (! in_array($studentId, array_map('intval', $pool->students ?: [$pool->student_id]), true)) {
            return null;
        }

        return StudentTournament::where('tournament_id', $pool->tournament_id)->where('student_id', $studentId)
            ->where(function ($q) use ($pool, $studentId) {
                $q->where('list_tournament_id', $pool->list_id)->orWhereExists(function ($membership) use ($pool, $studentId) {
                    $membership->selectRaw('1')->from('tournament_student_lists')
                        ->where('student_id', $studentId)->where('list_tournament_id', $pool->list_id);
                });
            })->first();
    }

    public function updateReason(User $actor, Tournament $tournament, KataPool $pool, ?User $student, ?StudentTournament $application, ?bool $assigned = null): ?string
    {
        if (! app(CoachTournamentAccess::class)->online($tournament)) {
            return 'not_online';
        }
        if (! $this->canView($actor, $tournament, $student, $assigned)) {
            return 'not_owner';
        }
        if ($pool->round !== 'FINAL') {
            return 'not_final';
        }
        if (! TournamentLifecycle::active($tournament)) {
            return 'closed';
        }
        if (! $application) {
            return 'no_application';
        }

        return null;
    }
}
