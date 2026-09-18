<?php

namespace App\Services\Tournaments\Kata;

use App\Models\KataPool;
use App\Models\ListTournament;
use Illuminate\Support\Collection;

final class KataState
{
    public static function snapshot(ListTournament $list, Collection $pools): array
    {
        return ['finalists_count' => (int) ($list->finalists_count ?? 4), 'pools' => $pools->sortBy('id')->mapWithKeys(function (KataPool $pool) {
            $row = $pool->only(['id', 'student_id', 'tournament_id', 'list_id', 'group_id', 'students', 'round', 'participant_number', 'rank', 'winner_1', 'winner_2', 'winner_3']);
            foreach ([...KataScores::FIELDS, ...KataScores::DERIVED] as $field) {
                $row[$field] = $pool->getAttributes()[$field] ?? null;
            }

            return [$pool->id => $row];
        })->all()];
    }

    public static function revision(ListTournament $list, Collection $pools): string
    {
        return hash_hmac('sha256', json_encode(self::snapshot($list, $pools), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public static function results(Collection $pools): bool
    {
        return $pools->contains(fn ($p) => $p->winner_1 || $p->winner_2 || $p->winner_3 || $p->rank);
    }

    public static function clearResults(Collection $pools): void
    {
        foreach ($pools as $pool) {
            $pool->rank = null;
            foreach (['winner_1', 'winner_2', 'winner_3'] as $field) {
                $pool->$field = false;
            }
        }
    }

    public static function removeFinal(Collection $pools): void
    {
        foreach ($pools->where('round', 'FINAL') as $key => $pool) {
            $pool->delete();
            $pools->forget($key);
        }
        self::clearResults($pools);
    }

    public static function confirm(bool $needed, bool $confirmed): void
    {
        if ($needed && ! $confirmed) {
            abort(response()->json(['code' => 'kata_confirmation_required', 'message' => __('kata.confirm_invalidation')], 409));
        }
    }

    public static function checkRevision(ListTournament $list, Collection $pools, ?string $revision): void
    {
        abort_unless($revision && hash_equals(self::revision($list, $pools), $revision), 409, __('kata.stale'));
    }
}
