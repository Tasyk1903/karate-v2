<?php

namespace App\Services\Tournaments;

use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentTournamentEnrollment
{
    public function admitted(User $student, Tournament $tournament): bool
    {
        return $student->projectRoleNames() === ['Student']
            && app(CoachTournamentAccess::class)->assigned(User::find($student->coach_id), $tournament);
    }

    public function canManage(User $student, Tournament $tournament): bool
    {
        $access = app(CoachTournamentAccess::class);

        return $this->admitted($student, $tournament) && $access->registrationOpen($tournament)
            && ($access->online($tournament) || User::query()->whereKey($student->coach_id)->where('can_attach_to_tournaments_for_students', true)->exists());
    }

    public function capabilities(User $student, Tournament $tournament): array
    {
        $query = TournamentStudentList::where('student_id', $student->id)->whereHas('listTournament', fn ($q) => $q->where('tournament_id', $tournament->id));
        $memberships = $query->with('listTournament.templateStudentList')->get();
        $open = $this->canManage($student, $tournament);

        return ['can_attach' => $open && ! app(CoachTournamentAccess::class)->online($tournament)
                && $memberships->whereNull('group_id')->isEmpty() && app(CoachTournamentAccess::class)->rankAllowed($student, $tournament),
            'memberships' => $memberships->map(fn ($row) => ['id' => $row->id, 'name' => $row->listTournament->templateStudentList?->name, 'can_detach' => $open])->values()];
    }

    public function attach(User $student, Tournament $tournament): void
    {
        DB::transaction(function () use ($student, $tournament): void {
            $tournament = Tournament::lockForUpdate()->findOrFail($tournament->id);
            $student = User::lockForUpdate()->findOrFail($student->id);
            if ($student->coach_id) {
                User::whereKey($student->coach_id)->lockForUpdate()->first();
            }
            abort_unless($this->canManage($student, $tournament), 403);
            $access = app(CoachTournamentAccess::class);
            if ($access->online($tournament)) {
                throw ValidationException::withMessages(['student' => __('mobile_tournaments.payment_required')]);
            }
            if (! $access->rankAllowed($student, $tournament)) {
                throw ValidationException::withMessages(['student' => __('mobile_tournaments.unavailable')]);
            }
            if (app(CoachTournamentEnrollment::class)->personalMemberships($tournament, $student->id)->exists()) {
                return;
            }
            $entry = StudentTournament::firstOrCreate(['student_id' => $student->id, 'tournament_id' => $tournament->id]);
            $list = app(StudentTournamentListAssignmentService::class)->assignToBestList($entry, $student, $tournament);
            TeamActivity::record($student, 'tournament.student.self_attached', StudentTournament::class, $entry->id,
                ['self' => true, 'tournament_id' => $tournament->id, 'old' => null, 'new' => ['student_id' => $student->id, 'list_tournament_id' => $list->id]]);
        }, 3);
    }

    public function detach(User $student, Tournament $tournament, int $membership): void
    {
        DB::transaction(function () use ($student, $tournament, $membership): void {
            $tournament = Tournament::lockForUpdate()->findOrFail($tournament->id);
            $student = User::lockForUpdate()->findOrFail($student->id);
            User::whereKey($student->coach_id)->lockForUpdate()->first();
            abort_unless($this->canManage($student, $tournament), 403);
            TournamentStudentList::where('student_id', $student->id)->whereHas('listTournament', fn ($q) => $q->where('tournament_id', $tournament->id))->whereKey($membership)->lockForUpdate()->firstOrFail();
            $entry = StudentTournament::where('tournament_id', $tournament->id)->where('student_id', $student->id)->lockForUpdate()->firstOrFail();
            app(TournamentApplications::class)->detach($entry, $membership, $student, selfOnly: true);
        }, 3);
    }
}
