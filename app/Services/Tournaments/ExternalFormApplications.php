<?php

namespace App\Services\Tournaments;

use App\Models\ExternalForm;
use App\Models\ListTournament;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExternalFormApplications
{
    public function __construct(private StudentTournamentListAssignmentService $lists) {}

    public function synchronize(ExternalForm $form, User $actor, array $desired, bool $allowPrune): array
    {
        $report = [];
        $wanted = collect($desired)->map(fn ($item) => $item['row']['row_id'].':'.$item['category']);
        $old = DB::table('external_form_applications')->where('external_form_id', $form->id)->get();
        // Preserve unknown/invalid submissions instead of interpreting them as an instruction to detach.
        if ($allowPrune) {
            foreach ($old as $application) {
                if ($wanted->contains($application->row_id.':'.$application->category)) {
                    continue;
                }
                try {
                    $removed = DB::transaction(fn () => $this->remove($form, $actor, $application));
                    $report[] = ['row_id' => $application->row_id, 'category' => $application->category, 'student_id' => $application->user_id, 'status' => $removed ? 'removed' : 'preserved'];
                } catch (ValidationException) {
                    $report[] = ['row_id' => $application->row_id, 'category' => $application->category, 'student_id' => $application->user_id, 'status' => 'locked'];
                }
            }
        }
        $batches = collect($desired)->groupBy(fn ($item) => $item['category'] === 'kata_group'
            ? 'group:'.$item['tournament']->id.':'.$item['row']['kata_group'] : 'row:'.$item['row']['row_id'].':'.$item['category']);
        foreach ($batches as $items) {
            $first = $items->first();
            $group = $first['category'] === 'kata_group';
            if ($group && ! $allowPrune) {
                foreach ($items as $item) {
                    $report[] = $this->result($item, 'group_invalid');
                }

                continue;
            }
            try {
                $batch = DB::transaction(function () use ($form, $actor, $items, $first, $group): array {
                    $tournament = Tournament::query()->lockForUpdate()->findOrFail($first['tournament']->id);
                    $groupId = $group ? $this->groupId($form, $tournament, (int) $first['row']['kata_group'], $items->pluck('user.id')->all()) : null;
                    $target = $group ? $this->lists->bestGroupList($tournament, $items->pluck('user')->unique('id')) : $this->lists->bestListFor($first['user'], $tournament);
                    $results = [];
                    foreach ($items as $item) {
                        $results[] = $this->attach($form, $actor, $item, $target, $groupId);
                    }

                    return $results;
                });
                array_push($report, ...$batch);
            } catch (ValidationException $error) {
                foreach ($items as $item) {
                    $report[] = $this->result($item, $error->errors()['application'][0] ?? 'locked');
                }
            }
        }

        return $report;
    }

    private function groupId(ExternalForm $form, Tournament $tournament, int $number, array $students): string
    {
        $where = ['external_form_id' => $form->id, 'tournament_id' => $tournament->id, 'number' => $number];
        $id = DB::table('external_form_groups')->where($where)->value('group_id');
        if (! $id) {
            // Recover a previous import's UUID, but never reuse one already assigned to another form/group.
            $legacyIds = TournamentStudentList::whereIn('student_id', $students)->whereNotNull('group_id')
                ->whereHas('listTournament', fn ($query) => $query->where('tournament_id', $tournament->id))->orderBy('id')->pluck('group_id');
            $claimed = DB::table('external_form_groups')->whereIn('group_id', $legacyIds)->pluck('group_id');
            $id = $legacyIds->diff($claimed)->first() ?? (string) Str::uuid();
            DB::table('external_form_groups')->insert($where + ['group_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function attach(ExternalForm $form, User $actor, array $item, ListTournament $target, ?string $groupId): array
    {
        $user = $item['user'];
        $where = ['external_form_id' => $form->id, 'row_id' => $item['row']['row_id'], 'category' => $item['category']];
        $previous = DB::table('external_form_applications')->where($where)->first();
        $existing = TournamentStudentList::where('list_tournament_id', $target->id)->where('student_id', $user->id)->where('group_id', $groupId)->first();
        if (! $previous && ! $existing && $user->is_external) {
            $legacy = TournamentStudentList::where('student_id', $user->id)->whereNull('source_external_form_id')
                ->whereHas('listTournament', fn ($query) => $query->where('tournament_id', $target->tournament_id))
                ->when($groupId, fn ($query) => $query->whereNotNull('group_id'), fn ($query) => $query->whereNull('group_id'))
                ->whereNotIn('id', DB::table('external_form_applications')->whereNotNull('membership_id')->select('membership_id'))->get();
            if ($legacy->count() === 1) {
                $existing = $legacy->first();
                $this->assertMutable($existing->listTournament);
                $this->assertMutable($target);
                $before = $existing->only(['list_tournament_id', 'group_id']);
                $existing->update(['list_tournament_id' => $target->id, 'group_id' => $groupId, 'source_external_form_id' => $form->id]);
                TeamActivity::record($actor, 'external_form.application.reconciled', TournamentStudentList::class, $existing->id,
                    ['form_id' => $form->id, 'old' => $before, 'new' => $existing->only(['list_tournament_id', 'group_id'])]);
            } elseif ($legacy->count() > 1) {
                throw ValidationException::withMessages(['application' => 'ambiguous_application']);
            }
        }
        if ($existing && $previous && (int) $previous->membership_id === (int) $existing->id) {
            return $this->result($item, 'unchanged', $target->id);
        }
        $this->assertMutable($target);
        if ($previous) {
            $this->remove($form, $actor, $previous);
        }
        $created = ! $existing;
        $membership = $existing ?? TournamentStudentList::create(['list_tournament_id' => $target->id, 'student_id' => $user->id, 'group_id' => $groupId, 'source_external_form_id' => $form->id]);
        DB::table('external_form_applications')->insert($where + ['tournament_id' => $target->tournament_id, 'user_id' => $user->id, 'membership_id' => $membership->id, 'created_at' => now(), 'updated_at' => now()]);
        $entry = StudentTournament::firstOrCreate(['student_id' => $user->id, 'tournament_id' => $target->tournament_id]);
        // Keep the legacy primary pointer on the personal application when both exist.
        if (! $entry->list_tournament_id || $groupId === null) {
            $entry->forceFill(['list_tournament_id' => $target->id])->save();
        }
        if ($user->coach_id && ! DB::table('tournament_treners')->where(['tournament_id' => $target->tournament_id, 'trener_id' => $user->coach_id])->exists()) {
            DB::table('tournament_treners')->insert(['tournament_id' => $target->tournament_id, 'trener_id' => $user->coach_id, 'created_at' => now(), 'updated_at' => now()]);
            TeamActivity::record($actor, 'external_form.coach.attached', Tournament::class, $target->tournament_id, ['form_id' => $form->id, 'coach_id' => $user->coach_id, 'old' => ['attached' => false], 'new' => ['attached' => true]]);
        }
        TeamActivity::record($actor, 'external_form.application.attached', TournamentStudentList::class, $membership->id,
            ['form_id' => $form->id, 'row_id' => $item['row']['row_id'], 'old' => $previous, 'new' => ['student_id' => $user->id, 'tournament_id' => $target->tournament_id, 'list_id' => $target->id, 'group_id' => $groupId, 'category' => $item['category']]]);

        return $this->result($item, $created ? 'attached' : 'unchanged', $target->id);
    }

    private function remove(ExternalForm $form, User $actor, object $application): bool
    {
        $membership = $application->membership_id ? TournamentStudentList::find($application->membership_id) : null;
        if ($membership) {
            $this->assertMutable($membership->listTournament);
        }
        DB::table('external_form_applications')->where('id', $application->id)->delete();
        if ($membership && (int) $membership->source_external_form_id === (int) $form->id
            && ! DB::table('external_form_applications')->where('membership_id', $membership->id)->exists()) {
            $membership->delete();
            $remaining = TournamentStudentList::where('student_id', $application->user_id)->whereHas('listTournament', fn ($query) => $query->where('tournament_id', $application->tournament_id))->orderBy('group_id')->first();
            $entry = StudentTournament::where('student_id', $application->user_id)->where('tournament_id', $application->tournament_id)->first();
            if ($entry && ! $remaining) {
                $entry->delete();
            } elseif ($entry && (int) $entry->list_tournament_id === (int) $membership->list_tournament_id) {
                $entry->update(['list_tournament_id' => $remaining->list_tournament_id]);
            }
        }
        TeamActivity::record($actor, 'external_form.application.removed', Tournament::class, $application->tournament_id,
            ['form_id' => $form->id, 'old' => (array) $application, 'new' => null, 'membership_preserved' => $membership && $membership->exists]);

        return ! $membership || ! $membership->exists;
    }

    private function assertMutable(ListTournament $list): void
    {
        if ($list->pools()->exists() || $list->kataPools()->exists() || !TournamentLifecycle::active($list->tournament)) {
            throw ValidationException::withMessages(['application' => 'locked']);
        }
    }

    private function result(array $item, string $status, ?int $list = null): array
    {
        return ['row_id' => $item['row']['row_id'], 'name' => trim($item['row']['last_name'].' '.$item['row']['first_name']),
            'student_id' => $item['user']->id, 'category' => $item['category'], 'tournament_id' => $item['tournament']->id, 'list_id' => $list, 'status' => $status];
    }
}
