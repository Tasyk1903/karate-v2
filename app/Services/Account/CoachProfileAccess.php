<?php

namespace App\Services\Account;

use App\Models\Tournament;
use App\Models\User;

final class CoachProfileAccess
{
    public function capabilities(User $coach, bool $lock = false): array
    {
        $canEdit = app(TournamentProfileAccess::class)->canEdit($coach, true, $lock);
        $participating = Tournament::query()
            ->whereDate('date_finish', '>=', today())
            ->whereHas('championship')
            ->whereHas('students', fn ($query) => $query->where('users.id', $coach->id))
            ->exists();

        return ['birthday' => (bool) $canEdit, 'rang' => (bool) $canEdit,
            'weight' => ! $participating, 'delete_account' => (bool) $canEdit];
    }
}
