<?php

namespace App\Services;

use App\Models\KataPool;
use App\Models\Pool;
use App\Models\Scale;
use App\Models\Tournament;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class StudentMedalService
{
    private const SCALES = [Scale::CITY, Scale::REGION, Scale::FEDERAL_DISTRICT, Scale::ALL_RUSSIAN, Scale::INTERNATIONAL, Scale::RUSSIAN_CHAMPIONSHIP];

    public function forStudent(User $student): array
    {
        $start = $student->getEffectiveCompetitiveRecordStartDate();
        $kumite = $this->bracketMedals($student, $this->tournaments(Tournament::KUMITE, $start));
        $kata = $this->bracketMedals($student, $this->tournaments(Tournament::KATA, $start)->where('tournament_type_kata', Tournament::FLAG_SYSTEM));
        $points = KataPool::query()
            ->whereIn('tournament_id', $this->tournaments(Tournament::KATA, $start)->where('tournament_type_kata', Tournament::POINT_SYSTEM)->select('id'))
            ->where('round', 'FINAL')
            ->where(fn (Builder $q) => $q->where('student_id', $student->id)
                ->orWhereJsonContains('students', $student->id)
                ->orWhereJsonContains('students', (string) $student->id))
            ->selectRaw('SUM(CASE WHEN winner_1 = 1 THEN 1 ELSE 0 END) AS gold, SUM(CASE WHEN winner_2 = 1 THEN 1 ELSE 0 END) AS silver, SUM(CASE WHEN winner_3 = 1 THEN 1 ELSE 0 END) AS bronze')
            ->first();
        foreach (array_keys($kata) as $medal) {
            $kata[$medal] += (int) $points->$medal;
        }

        return ['kumite' => $kumite, 'kata' => $kata];
    }

    private function tournaments(int $discipline, ?Carbon $start): Builder
    {
        return Tournament::query()->where('tournament_type', $discipline)
            ->whereHas('championship')
            ->whereHas('scale', fn (Builder $q) => $q->whereIn('slug', self::SCALES))
            ->when($start, fn (Builder $q) => $q->whereDate('date', '>=', $start->toDateString()));
    }

    private function bracketMedals(User $student, Builder $tournaments): array
    {
        $query = Pool::query()->whereIn('tournament_id', $tournaments->select('id'))
            ->where(fn (Builder $q) => $q->where('student_id', $student->id)->orWhere('opponent_id', $student->id)
                ->orWhere('winner_id_1rd_robbin', $student->id)->orWhere('winner_id_2rd_robbin', $student->id)->orWhere('winner_id_3rd_robbin', $student->id));
        $participant = '(student_id = ? OR opponent_id = ?)';
        $validWinner = '(winner_id = student_id OR winner_id = opponent_id)';
        $conditions = [
            'gold' => ["type = 'final' AND winner_id = ? AND $validWinner", [$student->id]],
            'silver' => ["type = 'final' AND $participant AND winner_id != ? AND $validWinner", [$student->id, $student->id, $student->id]],
            'bronze' => ["type = '3rd' AND winner_id = ? AND $validWinner", [$student->id]],
        ];
        foreach ($conditions as $medal => [$condition, $bindings]) {
            $query->selectRaw("COUNT(DISTINCT CASE WHEN $condition THEN tournament_id END) AS $medal", $bindings);
        }
        foreach (['gold' => 1, 'silver' => 2, 'bronze' => 3] as $medal => $place) {
            // The podium is repeated on every Round Robin bout, not only the winner's own bouts.
            $query->selectRaw("COUNT(DISTINCT CASE WHEN type = 'Round Robin' AND winner_id_{$place}rd_robbin = ? AND EXISTS (
                SELECT 1 FROM pools AS member WHERE member.tournament_id = pools.tournament_id AND member.list_id = pools.list_id
                AND member.type = 'Round Robin' AND (member.student_id = ? OR member.opponent_id = ?)
            ) THEN tournament_id END) AS rr_$medal", [$student->id, $student->id, $student->id]);
        }
        $counts = $query->first();

        return collect(['gold', 'silver', 'bronze'])->mapWithKeys(fn ($medal) => [$medal => (int) $counts->$medal + (int) $counts->{'rr_'.$medal}])->all();
    }
}
