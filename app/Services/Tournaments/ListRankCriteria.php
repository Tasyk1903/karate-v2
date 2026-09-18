<?php

namespace App\Services\Tournaments;

final class ListRankCriteria
{
    public function number(?string $rank): ?int
    {
        if (! preg_match('/^\s*(\d{1,2})\s*(кю|kyu|kyū|дан|dan)?\s*$/iu', $rank ?? '', $match)) {
            return null;
        }
        $number = (int) $match[1];
        if (in_array(mb_strtolower($match[2] ?? ''), ['дан', 'dan'], true)) {
            return $number >= 1 && $number <= 10 ? 0 : null;
        }

        return $number <= 10 ? ($number === 0 ? 10 : $number) : null;
    }

    public function matches(?string $rank, ?int $from, ?int $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }
        if ($from === null || $to === null) {
            return false;
        }
        $number = $this->number($rank);
        if ($number === null) {
            return false;
        }

        // Preserve legacy ascending-threshold lists; zero at the senior boundary includes dan ranks.
        return $from > $to ? $number <= $from && $number >= $to : $number >= $to;
    }
}
