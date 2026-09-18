<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TournamentStudentEligibility
{
    public function available(User $actor, Tournament $tournament, bool $group): Builder
    {
        $org = $actor->hasProjectRole('Organization') ? $actor->id : $actor->organization_id;

        return User::query()->role('Student')->whereHas('coach', fn ($q) => $q->role('Coach')->where('organization_id', $org))
            ->whereNotIn('id', DB::table('tournament_student_lists as memberships')->join('list_tournaments as lists', 'lists.id', '=', 'memberships.list_tournament_id')
                ->where('lists.tournament_id', $tournament->id)
                ->when($group, fn ($q) => $q->whereNotNull('group_id'), fn ($q) => $q->whereNull('group_id'))->select('memberships.student_id'));
    }
}
