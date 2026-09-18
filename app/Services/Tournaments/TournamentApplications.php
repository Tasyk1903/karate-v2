<?php

namespace App\Services\Tournaments;

use App\Models\ListTournament;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\Payments\KataPaymentReconciler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TournamentApplications
{
    public function assertMutable(ListTournament $list): void
    {
        if ($list->pools()->exists() || $list->kataPools()->exists()) {
            throw ValidationException::withMessages(['list' => __('lists.locked')]);
        }
    }

    public function membership(StudentTournament $entry, ?int $id): TournamentStudentList
    {
        $query = TournamentStudentList::where('student_id', $entry->student_id)->whereHas('listTournament', fn ($q) => $q->where('tournament_id', $entry->tournament_id));
        if ($id) {
            $query->whereKey($id);
        }
        $items = $query->lockForUpdate()->get();
        if ($items->count() !== 1) {
            throw ValidationException::withMessages(['membership_id' => __('lists.application')]);
        }

        return $items->first();
    }

    public function move(StudentTournament $entry, ?int $membershipId, int $targetId, User $actor): void
    {
        DB::transaction(function () use ($entry, $membershipId, $targetId, $actor): void {
            $tournament = Tournament::lockForUpdate()->findOrFail($entry->tournament_id);
            $source = $this->membership($entry, $membershipId);
            $target = ListTournament::with('templateStudentList')->where('tournament_id', $tournament->id)->findOrFail($targetId);
            app(ListCompatibility::class)->assert($target->templateStudentList, $tournament, $source->group_id !== null);
            if ($source->list_tournament_id == $targetId) {
                return;
            }
            $members = $this->composition($source, $tournament);
            $this->assertMutable($target);
            foreach ($members as $member) {
                $this->assertMutable($member->listTournament);
                if (TournamentStudentList::where('list_tournament_id', $targetId)->where('student_id', $member->student_id)->where('group_id', $member->group_id)->exists()) {
                    throw ValidationException::withMessages(['list' => __('lists.duplicate')]);
                }
            }
            foreach ($members as $member) {
                $old = $member->only(['list_tournament_id', 'group_id', 'student_id']);
                $member->update(['list_tournament_id' => $targetId]);
                $this->syncEntry($tournament->id, $member->student_id);
                TeamActivity::record($actor, 'tournament.application.moved', TournamentStudentList::class, $member->id,
                    ['tournament_id' => $tournament->id, 'old' => $old, 'new' => $member->only(array_keys($old))]);
            }
        });
    }

    public function detach(StudentTournament $entry, ?int $membershipId, User $actor, bool $selfOnly = false): void
    {
        DB::transaction(function () use ($entry, $membershipId, $actor, $selfOnly): void {
            $tournament = Tournament::lockForUpdate()->findOrFail($entry->tournament_id);
            $source = $this->membership($entry, $membershipId);
            if ($selfOnly) {
                abort_unless($actor->projectRoleNames() === ['Student'] && (int) $source->student_id === (int) $actor->id, 403);
            }
            $members = $selfOnly ? collect([$source]) : $this->composition($source, $tournament);
            foreach ($members as $member) {
                $this->assertMutable($member->listTournament);
            }
            foreach ($members as $member) {
                $old = $member->only(['list_tournament_id', 'group_id', 'student_id']);
                DB::table('external_form_applications')->where('membership_id', $member->id)->delete();
                $member->delete();
                $this->syncEntry($tournament->id, $member->student_id);
                if ($member->group_id === null && app(CoachTournamentAccess::class)->online($tournament)) {
                    app(KataPaymentReconciler::class)->markDetached($tournament, $member->student_id, $actor);
                }
                TeamActivity::record($actor, 'tournament.application.detached', TournamentStudentList::class, $member->id, ['tournament_id' => $tournament->id, 'old' => $old, 'new' => null]);
            }
        });
    }

    public function reassignWeight(StudentTournament $entry, User $student, Tournament $tournament, ?int $membershipId, User $actor): void
    {
        if ((int) $tournament->tournament_type !== Tournament::KUMITE) {
            return;
        }
        $membership = $this->membership($entry, $membershipId);
        app(ListCompatibility::class)->assert($membership->listTournament->templateStudentList, $tournament, false);
        if ($membership->group_id !== null) {
            throw ValidationException::withMessages(['membership_id' => __('lists.application')]);
        }
        $this->assertMutable($membership->listTournament);
        $target = app(StudentTournamentListAssignmentService::class)->bestListFor($student, $tournament);
        $this->move($entry, $membership->id, $target->id, $actor);
    }

    private function composition(TournamentStudentList $source, Tournament $tournament): Collection
    {
        return $source->group_id === null ? collect([$source]) : TournamentStudentList::with('listTournament')
            ->where('group_id', $source->group_id)->whereHas('listTournament', fn ($q) => $q->where('tournament_id', $tournament->id))->lockForUpdate()->get();
    }

    private function syncEntry(int $tournamentId, int $studentId): void
    {
        $membership = TournamentStudentList::where('student_id', $studentId)->whereHas('listTournament', fn ($q) => $q->where('tournament_id', $tournamentId))->orderBy('group_id')->orderBy('id')->first();
        $query = StudentTournament::where('student_id', $studentId)->where('tournament_id', $tournamentId);
        if (! $membership) {
            $query->delete();
        } else {
            $query->update(['list_tournament_id' => $membership->list_tournament_id]);
        }
    }
}
