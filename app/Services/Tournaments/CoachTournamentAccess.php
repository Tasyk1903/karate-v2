<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CoachTournamentAccess
{
    public function assigned(?User $coach, Tournament $tournament): bool
    {
        return $coach && ! $coach->trashed() && $coach->hasProjectRole('Coach')
            && ! $tournament->trashed() && $tournament->championship()->exists()
            && DB::table('tournament_treners')->where('tournament_id', $tournament->id)->where('trener_id', $coach->id)->exists();
    }

    public function canManage(?User $coach, Tournament $tournament): bool
    {
        return $this->assigned($coach, $tournament)
            && $coach->organization()->where('can_edit_coaches', true)->exists()
            && $this->registrationOpen($tournament);
    }

    public function canAttach(?User $coach, Tournament $tournament): bool
    {
        return $this->assigned($coach, $tournament)
            && $coach->organization()->where('can_edit_coaches', true)->exists()
            && ($this->online($tournament) ? $this->registrationOpen($tournament) : $this->applicationWindowOpen($tournament));
    }

    public function registrationOpen(Tournament $tournament): bool
    {
        return $this->applicationWindowOpen($tournament)
            && ! $tournament->pools()->exists() && ! $tournament->kataPools()->exists();
    }

    private function applicationWindowOpen(Tournament $tournament): bool
    {
        return $tournament->date && now()->lt($tournament->date->copy()->startOfDay())
            && $tournament->date_finish && now()->lte($tournament->date_finish)
            && (! $tournament->date_commission || now()->lte($tournament->date_commission));
    }

    public function online(Tournament $tournament): bool
    {
        return $tournament->is_online_kata && (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;
    }

    public function rankAllowed(User $student, Tournament $tournament): bool
    {
        $rank = app(ListRankCriteria::class)->number($student->rang);
        if ($tournament->KY_up_to_8 && ! $tournament->KY_from_8) {
            return in_array($rank, [10, 9], true);
        }
        if ($tournament->KY_from_8 && ! $tournament->KY_up_to_8) {
            return $rank !== null && $rank >= 0 && $rank <= 8;
        }

        return true;
    }
}
