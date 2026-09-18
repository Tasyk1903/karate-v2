<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class TournamentAge
{
    public static function onCommissionDay(?string $birthday, Tournament $tournament): ?int
    {
        // Legacy tournaments can lack a commission date; never fall back to today.
        $date = $tournament->date_commission ?? $tournament->date;
        if (blank($birthday) || $date === null) {
            return null;
        }

        $reference = CarbonImmutable::instance($date)->startOfDay();
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            try {
                $birth = CarbonImmutable::createFromFormat('!'.$format, $birthday, $reference->timezone);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($birth && $birth->format($format) === $birthday) {
                return $birth->greaterThan($reference) ? null : (int) $birth->diffInYears($reference);
            }
        }

        return null;
    }
}
