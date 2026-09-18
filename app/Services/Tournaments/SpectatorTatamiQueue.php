<?php

namespace App\Services\Tournaments;

use Illuminate\Support\Collection;

final class SpectatorTatamiQueue
{
    public function pending(Collection $pools, bool $kata): Collection
    {
        $field = $kata ? 'participant_number' : 'tatami_and_fight_number';
        $pending = $pools->filter(fn ($p) => $this->number($p->$field) !== null)
            ->filter(fn ($p) => $kata ? $p->rank === null : (! $p->winner_id && ! $p->absent_student && ! $p->absent_opponent))
            ->sortBy(fn ($p) => [$this->number($p->$field), $p->id])->values();
        if (! $kata) {
            $ready = $pending->filter(fn ($p) => $p->student_id && $p->opponent_id);
            if ($ready->isNotEmpty()) {
                return $ready->values();
            }
        }

        return $pending;
    }

    private function number(?string $code): ?int
    {
        if (preg_match('/(\\d+)(?!.*\\d)/u', (string) $code, $matches)) {
            return (int) $matches[1] > 0 ? (int) $matches[1] : null;
        }

        return null;
    }
}
