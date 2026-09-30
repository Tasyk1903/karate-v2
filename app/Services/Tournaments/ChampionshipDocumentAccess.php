<?php

namespace App\Services\Tournaments;

use App\Models\Championship;
use App\Models\User;

final class ChampionshipDocumentAccess
{
    public function manage(User $user, Championship $championship): bool
    {
        $organization = $user->hasProjectRole('Organization') ? $user->id
            : ($user->hasProjectRole('Secretary') ? $user->organization_id : null);

        return ! $championship->trashed() && $organization && (int) $organization === (int) $championship->organization_id;
    }

    public function view(User $user, Championship $championship): bool
    {
        if ($championship->trashed()) {
            return false;
        }

        // Coach can browse the championship catalogue, but not its private participant data.
        return $user->hasProjectRole('Coach') || $this->manage($user, $championship)
            || ($user->hasProjectRole('Student') && app(PanelTournamentVisibility::class)
                ->championships($user)->whereKey($championship->id)->exists());
    }
}
