<?php

namespace App\Services\Tournaments;

use App\Models\Championship;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PanelTournamentVisibility
{
    public function championships(User $user): Builder
    {
        $query = Championship::query()->select(['id', 'name', 'banner', 'organization_id', 'created_at']);

        if ($this->hasRole($user, 'Admin')) {
            return $query;
        }

        if ($this->hasRole($user, 'Organization')) {
            return $query->where('organization_id', $user->id);
        }

        if ($this->hasRole($user, 'Secretary')) {
            return $query->where('organization_id', $user->organization_id);
        }

        if ($this->hasRole($user, 'Coach')) {
            return $query->whereHas('tournaments', fn (Builder $tournaments) => $tournaments
                ->whereExists(function ($subQuery) use ($user): void {
                    $subQuery->selectRaw('1')
                        ->from('tournament_treners')
                        ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                        ->where('tournament_treners.trener_id', $user->id);
                }));
        }

        if ($this->hasRole($user, 'Student')) {
            if (! $this->hasLiveCoach($user)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('tournaments', fn (Builder $tournaments) => $tournaments
                ->whereExists(function ($subQuery) use ($user): void {
                    $subQuery->selectRaw('1')
                        ->from('tournament_treners')
                        ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                        ->where('tournament_treners.trener_id', $user->coach_id);
                }));
        }

        if ($this->hasRole($user, 'Judge')) {
            return $query->whereHas('tournaments', fn (Builder $tournaments) => $tournaments
                ->where('organization_id', $user->organization_id)
                ->where('tournament_type', Tournament::KATA)
                ->where('tournament_type_kata', Tournament::POINT_SYSTEM));
        }

        return $query->whereRaw('1 = 0');
    }

    public function tournaments(User $user): Builder
    {
        $query = Tournament::query()->with('championship:id,organization_id')->whereHas('championship')
            ->select([
                'id',
                'name',
                'championship_id',
                'region_id',
                'scale_id',
                'address',
                'date_commission',
                'date',
                'date_finish',
                'organization_id',
                'price',
                'created_at',
            ]);

        if ($this->hasRole($user, 'Admin')) {
            return $query;
        }

        if ($this->hasRole($user, 'Organization')) {
            return $query->where('organization_id', $user->id);
        }

        if ($this->hasRole($user, 'Secretary')) {
            return $query->where('organization_id', $user->organization_id);
        }

        if ($this->hasRole($user, 'Coach')) {
            return $query->whereExists(function ($subQuery) use ($user): void {
                $subQuery->selectRaw('1')
                    ->from('tournament_treners')
                    ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                    ->where('tournament_treners.trener_id', $user->id);
            });
        }

        if ($this->hasRole($user, 'Student')) {
            if (! $this->hasLiveCoach($user)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereExists(function ($subQuery) use ($user): void {
                $subQuery->selectRaw('1')
                    ->from('tournament_treners')
                    ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                    ->where('tournament_treners.trener_id', $user->coach_id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    private function hasRole(User $user, string $role): bool
    {
        return User::query()->whereKey($user->id)->role($role)->exists();
    }

    private function hasLiveCoach(User $student): bool
    {
        return $student->coach_id && User::query()->whereKey($student->coach_id)->role('Coach')->exists();
    }

    public function linkableIds(User $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->tournaments($viewer)->whereIn('id', $ids)
            ->whereIn('championship_id', $this->championships($viewer)->select('id'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
