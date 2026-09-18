<?php

namespace App\Services\Account;

use App\Models\Tournament;
use App\Models\User;

final class TournamentProfileAccess
{
    public function canEdit(User $participant, bool $byCoach, bool $lock = false): bool
    {
        $setting = $byCoach ? 'can_edit_coaches' : 'can_edit_students';
        $owners = Tournament::query()
            ->whereHas('championship', fn ($query) => $query->whereColumn('championships.organization_id', 'tournaments.organization_id'))
            ->whereDate('date_finish', '>=', today())
            ->whereHas('students', fn ($query) => $query->where('users.id', $participant->id))
            ->select('organization_id');

        // A home organization cannot lock participants of somebody else's event.
        return User::query()->whereIn('id', $owners)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', $setting])
            ->every(fn (User $owner): bool => (bool) $owner->{$setting});
    }
}
