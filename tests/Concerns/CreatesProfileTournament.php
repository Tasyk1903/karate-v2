<?php

namespace Tests\Concerns;

use App\Models\Championship;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;

trait CreatesProfileTournament
{
    private function profileTournament(User $owner, ?User $participant = null): Tournament
    {
        $championship = Championship::forceCreate(['name' => 'Profile permissions', 'banner' => 'test.jpg', 'organization_id' => $owner->id]);
        $tournament = Tournament::forceCreate(['name' => 'Profile permissions', 'organization_id' => $owner->id,
            'championship_id' => $championship->id, 'tournament_type' => Tournament::KUMITE,
            'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'address' => 'Test',
            'date' => today()->addDay(), 'date_commission' => today(), 'date_finish' => today()->addDays(2)]);
        if ($participant) {
            StudentTournament::forceCreate(['student_id' => $participant->id, 'tournament_id' => $tournament->id]);
        }

        return $tournament;
    }
}
