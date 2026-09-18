<?php

namespace App\Services\Exports;

use App\Models\Tournament;
use App\Models\User;
use App\Services\ProjectPermission;
use App\Services\Tournaments\MobileTournamentAccess;

final class MobileTournamentExports
{
    public function formats(User $actor, Tournament $tournament): array
    {
        if (! app(MobileTournamentAccess::class)->assigned($actor, $tournament)) {
            return [];
        }
        $formats = ['lists-excel', 'lists-pdf'];
        if ((int) $tournament->tournament_type === Tournament::KATA && $tournament->kataPools()->exists()) {
            $formats[] = 'kata-tables';
        }
        if ($actor->projectRoleNames() === ['Student'] && ProjectPermission::allows($actor, 'download_puli_tournament') && $tournament->pools()->exists()) {
            $formats[] = 'brackets';
        }

        return $formats;
    }

    public function authorize(User $actor, Tournament $tournament, string $format): void
    {
        abort_unless(in_array($format, $this->formats($actor, $tournament), true), 403);
    }
}
