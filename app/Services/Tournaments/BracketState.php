<?php

namespace App\Services\Tournaments;

use App\Models\Pool;
use Illuminate\Support\Collection;

final class BracketState
{
    public const SCORES = ['student_wazari_count', 'opponent_wazari_count', 'student_ippon', 'opponent_ippon'];

    public const PLACES = ['winner_id_1rd_robbin', 'winner_id_2rd_robbin', 'winner_id_3rd_robbin'];

    public static function reset(Pool $pool): void
    {
        $pool->winner_id = null;
        $pool->absent_student = false;
        $pool->absent_opponent = false;
        foreach (self::SCORES as $field) {
            $pool->$field = 0;
        }
        foreach (self::PLACES as $field) {
            $pool->$field = null;
        }
    }

    public static function snapshot(Collection $pools): array
    {
        return $pools->sortBy('id')->mapWithKeys(function (Pool $pool) {
            $row = $pool->only(['id', 'tournament_id', 'list_id', 'round', 'position_in_round', 'type', 'student_id', 'opponent_id', 'winner_id', 'tatami_and_fight_number', 'absent_student', 'absent_opponent', ...self::SCORES, ...self::PLACES]);
            foreach (['id', 'tournament_id', 'list_id', 'round', 'position_in_round', 'student_id', 'opponent_id', 'winner_id', ...self::PLACES] as $field) {
                $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
            }
            foreach (['absent_student', 'absent_opponent', ...self::SCORES] as $field) {
                $row[$field] = (int) ($row[$field] ?? 0);
            }

            return [$pool->id => $row];
        })->all();
    }

    public static function version(Collection $pools): string
    {
        return hash('sha256', json_encode(self::snapshot($pools)));
    }

    public static function hasResults(Collection $pools): bool
    {
        return $pools->contains(fn (Pool $p) => $p->winner_id || $p->absent_student || $p->absent_opponent
            || collect([...self::SCORES, ...self::PLACES])->contains(fn ($field) => (bool) $p->$field));
    }
}
