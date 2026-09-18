<?php

namespace App\Services\Students;

use App\Models\Pool;
use App\Models\Scale;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\PanelTournamentVisibility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class StudentCompetitionHistory
{
    public function record(User $student): array
    {
        $row = $this->fights($student)->select([])->selectRaw('COUNT(*) as total, SUM(CASE WHEN pools.winner_id = ? THEN 1 ELSE 0 END) as wins', [$student->id])->first();
        $total = (int) ($row->total ?? 0);
        $wins = (int) ($row->wins ?? 0);

        return ['wins' => $wins, 'losses' => $total - $wins, 'total' => $total];
    }

    public function page(User $student, User $viewer, string $kind, int $page = 1, int $perPage = 20): array
    {
        if ($kind === 'tournaments') {
            $query = Tournament::query()->whereHas('championship')
                ->whereHas('students', fn ($q) => $q->where('users.id', $student->id))
                ->whereHas('scale', fn ($q) => $q->whereIn('slug', [Scale::CITY, Scale::REGION, Scale::FEDERAL_DISTRICT, Scale::ALL_RUSSIAN, Scale::INTERNATIONAL, Scale::RUSSIAN_CHAMPIONSHIP]))
                ->when($student->getEffectiveCompetitiveRecordStartDate(), fn ($q, $date) => $q->whereDate('date', '>=', $date->toDateString()))
                ->orderByDesc('date')->orderByDesc('id');
            $rows = $query->paginate($perPage, ['id', 'championship_id', 'name', 'date', 'tournament_type'], 'page', $page);
            $ids = $rows->getCollection()->pluck('id')->all();
            $counts = $this->fights($student)->whereIn('pools.tournament_id', $ids)->select([])->selectRaw('pools.tournament_id, SUM(CASE WHEN pools.winner_id = ? THEN 1 ELSE 0 END) AS wins, SUM(CASE WHEN pools.winner_id != ? THEN 1 ELSE 0 END) AS losses', [$student->id, $student->id])->groupBy('pools.tournament_id')->get()->keyBy('tournament_id');
            $linkable = array_flip(app(PanelTournamentVisibility::class)->linkableIds($viewer, $ids));
            $rows->through(fn ($t) => ['id' => $t->id, 'championship_id' => $t->championship_id, 'name' => $t->name,
                'date' => $this->dateLabel($t->date), 'type' => (int) $t->tournament_type === Tournament::KATA ? 'kata' : 'kumite',
                'wins' => (int) ($counts->get($t->id)?->wins ?? 0), 'losses' => (int) ($counts->get($t->id)?->losses ?? 0),
                'can_open' => isset($linkable[$t->id])]);
        } else {
            $rows = $this->fights($student)->with([
                'tournament:id,championship_id,name,date_finish', 'listTournament:id,template_student_list_id',
                'listTournament.templateStudentList:id,name', 'student:id,first_name,last_name,avatar,birthday,coach_id',
                'opponent:id,first_name,last_name,avatar,birthday,coach_id', 'student.coach:id,first_name,last_name,club',
                'opponent.coach:id,first_name,last_name,club',
            ])->where('pools.winner_id', $kind === 'wins' ? '=' : '!=', $student->id)
                ->orderByDesc('tournaments.date_finish')->orderByDesc('pools.id')->paginate($perPage, ['pools.*'], 'page', $page);
            $ids = $rows->getCollection()->pluck('tournament_id')->unique()->all();
            $linkable = array_flip(app(PanelTournamentVisibility::class)->linkableIds($viewer, $ids));
            $rows->through(function ($pool) use ($student, $linkable) {
                $row = $this->formatFightRecord($pool, $student);
                $row['tournament']['can_open'] = isset($linkable[$pool->tournament_id]);

                return $row;
            });
        }

        return ['data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
    }

    public function fights(User $student): Builder
    {
        $startDate = $student->getEffectiveCompetitiveRecordStartDate();

        return Pool::query()
            ->join('tournaments', 'pools.tournament_id', '=', 'tournaments.id')
            ->where(function (Builder $query) use ($student): void {
                $query
                    ->where('pools.student_id', $student->id)
                    ->orWhere('pools.opponent_id', $student->id);
            })
            ->whereNotNull('pools.winner_id')
            ->where('tournaments.tournament_type', Tournament::KUMITE)
            ->whereNull('tournaments.deleted_at')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('championships')
                    ->whereColumn('championships.id', 'tournaments.championship_id')
                    ->whereNull('championships.deleted_at');
            })
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('scales')
                    ->whereColumn('scales.id', 'tournaments.scale_id')
                    ->whereIn('scales.slug', [
                        Scale::CITY,
                        Scale::REGION,
                        Scale::FEDERAL_DISTRICT,
                        Scale::ALL_RUSSIAN,
                        Scale::INTERNATIONAL,
                        Scale::RUSSIAN_CHAMPIONSHIP,
                    ]);
            })
            ->when($startDate, fn (Builder $query) => $query->whereDate('tournaments.date', '>=', $startDate->toDateString()))
            ->select('pools.*');
    }

    private function formatFightRecord(Pool $pool, User $student): array
    {
        $opponent = (int) $pool->student_id === (int) $student->id
            ? $pool->opponent
            : $pool->student;

        return [
            'id' => $pool->id,
            'opponent' => [
                'id' => $opponent?->id,
                'first_name' => $opponent?->first_name,
                'last_name' => $opponent?->last_name,
                'full_name' => trim(($opponent?->last_name ?? '').' '.($opponent?->first_name ?? '')),
                'club' => $opponent?->coach?->club,
                'avatar' => $opponent?->avatar ? asset('storage/'.$opponent->avatar) : null,
                'age_at_fight' => $this->ageAtDate($opponent?->birthday, $pool->tournament?->date_finish),
                'coach_name' => $opponent?->coach
                    ? trim($opponent->coach->last_name.' '.$opponent->coach->first_name)
                    : null,
            ],
            'fight_date' => $this->dateLabel($pool->tournament?->date_finish),
            'tournament' => [
                'id' => $pool->tournament?->id,
                'championship_id' => $pool->tournament?->championship_id,
                'name' => $pool->tournament?->name,
            ],
            'pool' => $pool->listTournament?->templateStudentList?->name,
        ];
    }

    private function ageAtDate(mixed $birthday, mixed $date): ?string
    {
        if (! $birthday || ! $date) {
            return null;
        }

        $age = Carbon::parse($birthday)->diff(Carbon::parse($date))->y;
        if (app()->getLocale() === 'en') {
            return "{$age} years";
        }
        $suffix = match (true) {
            $age % 10 === 1 && $age % 100 !== 11 => 'год',
            in_array($age % 10, [2, 3, 4], true) && ! in_array($age % 100, [12, 13, 14], true) => 'года',
            default => 'лет',
        };

        return "{$age} {$suffix}";
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d.m.Y') : null;
    }
}
