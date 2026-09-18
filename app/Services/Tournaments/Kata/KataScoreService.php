<?php

namespace App\Services\Tournaments\Kata;

use App\Models\KataPool;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class KataScoreService
{
    public function update(User $actor, KataPool $pool, string $field, mixed $value, mixed $original, bool $confirmed, ?string $revision): void
    {
        abort_unless(in_array($field, KataScores::FIELDS, true), 422);
        $value = KataScores::normalize($value);
        abort_unless($original === null || is_string($original) || is_int($original) || is_float($original), 422);
        app(KataMutation::class)->run($actor, $pool->tournament_id, $pool->list_id, 'tournament.kata.score_updated', ['kata_pool_id' => $pool->id, 'field' => $field],
            function ($pools, $list) use ($pool, $field, $value, $original, $confirmed, $revision) {
                $target = $pools->firstWhere('id', $pool->id);
                abort_unless($target, 404);
                $stored = $target->getRawOriginal($field);
                $normalizedStored = str_replace(',', '.', trim((string) $stored));
                $normalizedOriginal = str_replace(',', '.', trim((string) $original));
                abort_unless($normalizedStored === $normalizedOriginal || (is_numeric($normalizedStored) && is_numeric($normalizedOriginal) && (float) $normalizedStored === (float) $normalizedOriginal), 409, __('kata.cell_stale'));
                try {
                    $sameScore = KataScores::normalize($stored) === $value;
                } catch (ValidationException) {
                    $sameScore = false;
                }
                $calculated = clone $target;
                $calculated->$field = $value;
                KataScores::calculate($calculated);
                $sameTotals = collect(KataScores::DERIVED)->every(fn ($key) => $target->$key === $calculated->$key);
                if ($sameScore && $sameTotals) {
                    return;
                }
                $final = $pools->where('round', 'FINAL');
                $invalidate = $target->round === 'PRELIMINARY STAGE' ? $final->isNotEmpty() || KataState::results($pools) : KataState::results($final);
                KataState::confirm($invalidate, $confirmed);
                if ($invalidate) {
                    KataState::checkRevision($list, $pools, $revision);
                }
                if ($target->round === 'PRELIMINARY STAGE') {
                    KataState::removeFinal($pools);
                } else {
                    KataState::clearResults($final);
                }
                $target->$field = $value;
                KataScores::calculate($target);
                // Float casts consider legacy "8.00" equal to "8.0"; persist the validated cell explicitly.
                $target->newQuery()->whereKey($target->id)->update([$field => $value]);
                $target->syncOriginalAttribute($field);
            }, $field);
    }
}
