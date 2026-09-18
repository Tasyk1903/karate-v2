<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TournamentListProgressService
{
    public function statsFor(Tournament $tournament, Collection $listIds): array
    {
        if ($listIds->isEmpty()) {
            return [];
        }

        $stats = $this->isPointKata($tournament)
            ? $this->kataStats($tournament, $listIds)
            : $this->kumiteStats($tournament, $listIds);

        return $stats->mapWithKeys(function ($stat, int|string $listId): array {
            $total = (int) ($stat->total_count ?? 0);
            $completed = (int) ($stat->completed_count ?? 0);

            return [
                (int) $listId => [
                    'generated_count' => (int) ($stat->generated_count ?? $total),
                    'total_count' => $total,
                    'completed_count' => $completed,
                    'completion_percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
                ],
            ];
        })->all();
    }

    private function kumiteStats(Tournament $tournament, Collection $listIds): Collection
    {
        return DB::table('pools')
            ->where('tournament_id', $tournament->id)
            ->whereIn('list_id', $listIds)
            ->selectRaw('list_id, COUNT(*) as generated_count')
            ->selectRaw("
                SUM(
                    CASE
                        WHEN type = 'Round Robin'
                            THEN 1
                        WHEN student_id IS NOT NULL AND opponent_id IS NOT NULL
                            THEN 1
                        ELSE 0
                    END
                ) as total_count
            ")
            ->selectRaw("
                SUM(
                    CASE
                        WHEN type = 'Round Robin'
                            AND (
                                (winner_id_1rd_robbin IS NOT NULL AND winner_id_2rd_robbin IS NOT NULL)
                                OR absent_student = 1
                                OR absent_opponent = 1
                            )
                            THEN 1
                        WHEN (type IS NULL OR type <> 'Round Robin')
                            AND student_id IS NOT NULL
                            AND opponent_id IS NOT NULL
                            AND (
                                winner_id IS NOT NULL
                                OR absent_student = 1
                                OR absent_opponent = 1
                            )
                            THEN 1
                        ELSE 0
                    END
                ) as completed_count
            ")
            ->groupBy('list_id')
            ->get()
            ->keyBy('list_id');
    }

    private function kataStats(Tournament $tournament, Collection $listIds): Collection
    {
        return DB::table('kata_pools')
            ->where('tournament_id', $tournament->id)
            ->whereIn('list_id', $listIds)
            ->selectRaw('list_id, COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN total_score IS NOT NULL AND `rank` IS NOT NULL THEN 1 ELSE 0 END) as completed_count')
            ->groupBy('list_id')
            ->get()
            ->keyBy('list_id');
    }

    private function isPointKata(Tournament $tournament): bool
    {
        return (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;
    }
}
