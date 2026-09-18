<?php

namespace App\Services\Tournaments;

use App\Models\ListTournament;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentTournamentListAssignmentService
{
    public function assignToBestList(StudentTournament $studentTournament, User $student, Tournament $tournament): ListTournament
    {
        return $this->assignToList($studentTournament, $student, $this->bestListFor($student, $tournament));
    }

    public function bestListFor(User $student, Tournament $tournament): ListTournament
    {
        $tournament->loadMissing('lists.templateStudentList');

        $age = TournamentAge::onCommissionDay($student->birthday, $tournament);

        foreach ($this->matchingCandidateLists($tournament) as $list) {
            if ($this->studentMatchesList($student, $list->templateStudentList, $age, (int) $tournament->tournament_type)) {
                return $list;
            }
        }

        return $this->defaultListTournament($tournament);
    }

    public function assignToList(StudentTournament $studentTournament, User $student, ListTournament $list, ?string $groupId = null): ListTournament
    {
        app(ListCompatibility::class)->assert($list->templateStudentList, $list->tournament, $groupId !== null);
        // Personal entries join the source list, not the already generated draw.
        // Paid online stages and existing group compositions stay protected.
        if ($groupId !== null || app(CoachTournamentAccess::class)->online($list->tournament)) {
            app(TournamentApplications::class)->assertMutable($list);
        }
        TournamentStudentList::query()->firstOrCreate([
            'list_tournament_id' => $list->id,
            'student_id' => $student->id,
            'group_id' => $groupId,
        ]);

        if (! $studentTournament->list_tournament_id || $groupId === null) {
            $studentTournament->forceFill(['list_tournament_id' => $list->id])->save();
        }

        return $list;
    }

    public function assignGroupToBestList(StudentTournament $studentTournament, User $student, Tournament $tournament, string $groupId): ListTournament
    {
        return $this->assignToList($studentTournament, $student, $this->bestGroupList($tournament, collect([$student])), $groupId);
    }

    public function bestGroupList(Tournament $tournament, Collection $students): ListTournament
    {
        $tournament->loadMissing('lists.templateStudentList');

        foreach ($this->groupCandidateLists($tournament) as $list) {
            $template = $list->templateStudentList;
            if ($template && $students->isNotEmpty() && $students->every(function (User $student) use ($template, $tournament): bool {
                $age = TournamentAge::onCommissionDay($student->birthday, $tournament);

                return $age !== null && $age >= (int) $template->age_from && $age <= (int) $template->age_to
                    && (blank($template->gender) || $template->gender === 'all' || $student->gender === $template->gender);
            })) {
                return $list;
            }
        }

        return $this->defaultListTournament($tournament, true);
    }

    public function groupAgesMatch(Collection $students, Tournament $tournament): bool
    {
        $ages = $students->map(fn (User $student): ?int => TournamentAge::onCommissionDay($student->birthday, $tournament));
        $categories = [
            [4, 5],
            [6, 7],
            [8, 9],
            [10, 11],
            [12, 13],
            [14, 15],
            [16, 17],
            [18, 100],
        ];

        return $ages->isNotEmpty() && collect($categories)->contains(fn (array $range): bool => $ages->every(fn (?int $age): bool => $age !== null && $age >= $range[0] && $age <= $range[1]));
    }

    public function normalizeRank(string $rank): int
    {
        return app(ListRankCriteria::class)->number($rank) ?? -1;
    }

    private function matchingCandidateLists(Tournament $tournament)
    {
        return $tournament->lists->sortBy('id')->filter(fn (ListTournament $list) => app(ListCompatibility::class)->matches($list->templateStudentList, $tournament, false)
            && ! str_starts_with($list->templateStudentList->name, 'Ученики которые не попали в списки'));
    }

    private function groupCandidateLists(Tournament $tournament)
    {
        return $tournament->lists->filter(
            fn (ListTournament $list): bool => app(ListCompatibility::class)->matches($list->templateStudentList, $tournament, true)
                && $list->templateStudentList?->name !== 'Ученики которые не попали в списки (КАТА - Групповые)'
        );
    }

    private function defaultListTournament(Tournament $tournament, bool $group = false): ListTournament
    {
        return DB::transaction(function () use ($tournament, $group): ListTournament {
            User::query()->whereKey($tournament->organization_id)->lockForUpdate()->firstOrFail();
            $isKata = (int) $tournament->tournament_type === Tournament::KATA;
            $isPointKata = $isKata && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;
            $listType = $isKata ? TemplateStudentList::KATA : TemplateStudentList::KUMITE;
            $kataType = match (true) {
                $group => TemplateStudentList::GROUP,
                $isPointKata => TemplateStudentList::PERSONAL,
                $isKata => TemplateStudentList::FLAG,
                default => null,
            };

            $template = TemplateStudentList::query()->firstOrCreate([
                'name' => match (true) {
                    $group => 'Ученики которые не попали в списки (КАТА - Групповые)',
                    $isPointKata => 'Ученики которые не попали в списки (КАТА - Личные)',
                    $isKata => 'Ученики которые не попали в списки (КАТА - Флажковая)',
                    default => 'Ученики которые не попали в списки',
                },
                'user_id' => $tournament->organization_id,
                'list_type' => $listType,
                'kata_type' => $kataType,
            ], [
                'age_from' => 0,
                'age_to' => 100,
                'weight_from' => 0,
                'weight_to' => 100,
                'rang_from' => 0,
                'rang_to' => 100,
                'gender' => 'all',
            ]);

            return ListTournament::query()->firstOrCreate([
                'tournament_id' => $tournament->id,
                'template_student_list_id' => $template->id,
            ]);
        });
    }

    private function studentMatchesList(User $student, ?TemplateStudentList $list, ?int $age, int $tournamentType): bool
    {
        if (! $list || $age === null) {
            return false;
        }

        $genderOk = blank($list->gender) || $list->gender === 'all' || $student->gender === $list->gender;
        $base = $age >= (int) $list->age_from && $age <= (int) $list->age_to && $genderOk;

        if ($tournamentType === Tournament::KUMITE) {
            $weight = (float) ($student->weight ?? 0);
            $base = $base && $weight >= (float) $list->weight_from && $weight <= (float) $list->weight_to;
        }

        $rankOk = ($tournamentType === Tournament::KATA && $list->kata_type === TemplateStudentList::PERSONAL)
            || app(ListRankCriteria::class)->matches($student->rang, $list->rang_from, $list->rang_to);

        return $base && $rankOk;
    }
}
