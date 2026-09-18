<?php

namespace App\Services\Tournaments;

use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CoachTournamentEnrollment
{
    public function personalMemberships(Tournament $tournament, ?int $studentId = null): Builder
    {
        return TournamentStudentList::query()->whereNull('group_id')
            ->whereHas('listTournament', fn (Builder $q) => $q->where('tournament_id', $tournament->id))
            ->when($studentId !== null, fn (Builder $q) => $q->where('student_id', $studentId));
    }

    public function options(User $coach, Tournament $tournament, string $search = ''): Builder
    {
        $query = User::query()->role('Student')->where('coach_id', $coach->id)
            ->whereNotIn('id', $this->personalMemberships($tournament)->select('student_id'));
        foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn (Builder $q) => $q->where('first_name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%"));
        }

        // Legacy rank strings have several spellings. Filter once before paginating,
        // then revalidate locked rows on submission; no MySQL-only regexp is required.
        if ((bool) $tournament->KY_up_to_8 !== (bool) $tournament->KY_from_8) {
            $ids = (clone $query)->select(['id', 'rang'])->get()
                ->filter(fn (User $s) => app(CoachTournamentAccess::class)->rankAllowed($s, $tournament))->modelKeys();
            $query->whereIn('id', $ids);
        }

        return $query->with('coach:id,club')->select(['id', 'first_name', 'last_name', 'avatar', 'birthday', 'age', 'weight', 'rang', 'coach_id'])->orderBy('last_name')->orderBy('first_name')->orderBy('id');
    }

    public function attach(User $coach, Tournament $tournament, array $ids): array
    {
        return DB::transaction(function () use ($coach, $tournament, $ids): array {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            abort_unless(app(CoachTournamentAccess::class)->canAttach($coach, $tournament), 403);
            if (app(CoachTournamentAccess::class)->online($tournament)) {
                throw ValidationException::withMessages(['student_ids' => __('mobile_tournaments.payment_required')]);
            }
            $students = User::query()->role('Student')->where('coach_id', $coach->id)->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $errors = [];
            foreach ($ids as $index => $id) {
                if (! isset($students[$id]) || ! app(CoachTournamentAccess::class)->rankAllowed($students[$id], $tournament)) {
                    $errors["student_ids.$index"] = __('mobile_tournaments.unavailable');
                } elseif ($this->personalMemberships($tournament, $id)->exists()) {
                    $errors["student_ids.$index"] = __('mobile_tournaments.already_attached');
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $attached = [];
            foreach ($ids as $id) {
                $entry = StudentTournament::firstOrCreate(['student_id' => $id, 'tournament_id' => $tournament->id]);
                $old = $entry->only(['id', 'student_id', 'list_tournament_id']);
                $list = app(StudentTournamentListAssignmentService::class)->assignToBestList($entry, $students[$id], $tournament);
                $row = ['student_id' => (int) $id, 'student_tournament_id' => $entry->id, 'list_tournament_id' => $list->id];
                TeamActivity::record($coach, 'mobile.tournament.student.attached', StudentTournament::class, $entry->id, ['old' => $old, 'new' => $row, 'tournament_id' => $tournament->id]);
                $attached[] = $row;
            }

            return $attached;
        });
    }

    public function detach(User $coach, Tournament $tournament, StudentTournament $entry): void
    {
        DB::transaction(function () use ($coach, $tournament, $entry): void {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            abort_unless(app(CoachTournamentAccess::class)->canManage($coach, $tournament), 403);
            $student = User::query()->role('Student')->whereKey($entry->student_id)->where('coach_id', $coach->id)->lockForUpdate()->first();
            abort_unless($student && (int) $entry->tournament_id === (int) $tournament->id, 403);
            $memberships = $this->personalMemberships($tournament, $student->id)->lockForUpdate()->get();
            if ($memberships->count() !== 1) {
                throw ValidationException::withMessages(['student' => __('mobile_tournaments.ambiguous')]);
            }
            app(TournamentApplications::class)->detach($entry, $memberships->first()->id, $coach);
        });
    }
}
