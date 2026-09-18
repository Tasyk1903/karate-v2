<?php

namespace App\Services\Students;

use App\Models\User;
use Carbon\Carbon;

final class CompetitiveRecordPeriod
{
    public function qualifies(?string $rank): bool
    {
        $rank = mb_strtolower(trim($rank ?? ''));
        if (str_contains($rank, 'дан') || str_contains($rank, 'dan')) {
            return true;
        }
        $number = (int) filter_var($rank, FILTER_SANITIZE_NUMBER_INT);

        return $number >= 1 && $number <= 8;
    }

    public function synchronize(User $student): void
    {
        if (! $this->qualifies($student->rang)) {
            return;
        }
        $start = $student->competitive_record_starts_at ? Carbon::parse($student->competitive_record_starts_at)->startOfDay() : null;
        if ($this->qualifies($student->getOriginal('rang')) && (! $student->isDirty('birthday') || ($start && ! $start->isFuture()))) {
            return;
        }
        $eighthBirthday = $student->birthday ? Carbon::parse($student->birthday)->startOfDay()->addYears(8) : today();
        $student->competitive_record_starts_at = $eighthBirthday->max(today())->toDateString();
    }
}
