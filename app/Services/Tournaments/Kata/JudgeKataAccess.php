<?php

namespace App\Services\Tournaments\Kata;

use App\Models\ListTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class JudgeKataAccess
{
    public static function views(User $user, Tournament $tournament): bool
    {
        return $user->projectRoleNames() === ['Judge'] && ! $user->is_external
            && $user->organization_id && (int) $user->organization_id === (int) $tournament->organization_id
            && (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM
            && TournamentLifecycle::active($tournament) && $tournament->championship()->exists();
    }

    public function lists(User $user): Builder
    {
        abort_unless($user->projectRoleNames() === ['Judge'] && $user->organization_id, 403);

        return ListTournament::query()->whereHas('tournament', fn ($q) => $q
            ->where('organization_id', $user->organization_id)->where('tournament_type', Tournament::KATA)
            ->where('tournament_type_kata', Tournament::POINT_SYSTEM)->whereDate('date_finish', '>=', today())
            ->whereHas('championship'))
            ->whereHas('templateStudentList', fn ($q) => $q->where('list_type', TemplateStudentList::KATA))
            ->whereHas('kataPools');
    }

    public static function stageOpen(string $round, Collection $pools): bool
    {
        return $round === 'PRELIMINARY STAGE'
            ? ! $pools->contains('round', 'FINAL')
            : ($round === 'FINAL' && ! KataState::results($pools->where('round', 'FINAL')));
    }
}
