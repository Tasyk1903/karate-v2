<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\ListTournament;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\Tournaments\Kata\JudgeKataAccess;
use App\Services\Tournaments\StudentTournamentEnrollment;
use App\Services\Tournaments\StudentTournamentListAssignmentService;
use App\Services\Tournaments\TournamentAge;
use App\Services\Tournaments\TournamentApplications;
use App\Services\Tournaments\TournamentStudentEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TournamentStudentController extends BaseTournamentController
{
    public function __construct(Request $request)
    {
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
    }

    public function attachOptions(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        abort_unless($this->canOrganizationAttachGroupStudents($tournament), 403);
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $search = trim((string) $request->query('search', ''));
        $query = app(TournamentStudentEligibility::class)->available($request->user(), $tournament, true)
            ->with('coach:id,club');
        foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $query->where(fn ($names) => $names->where('first_name', 'like', '%'.$part.'%')->orWhere('last_name', 'like', '%'.$part.'%'));
        }
        $page = $query->orderBy('last_name')->orderBy('id')->paginate(30, ['id', 'first_name', 'last_name', 'rang', 'birthday', 'coach_id']);

        return response()->json(['data' => $page->getCollection()->map(fn ($student) => ['id' => $student->id, 'name' => $student->full_name, 'rang' => $student->rang, 'age' => TournamentAge::onCommissionDay($student->birthday, $tournament), 'club' => $student->coach?->club]), 'meta' => $this->meta($page)]);
    }

    public function showOnlineKataVideo(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        int $studentTournament,
        string $round,
    ) {
        $user = $request->user();

        abort_unless($user, 403);
        $this->authorizeChampionship($user, $championship);
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);
        abort_unless((bool) $tournament->is_online_kata, 404);
        abort_unless(in_array($round, ['first', 'second'], true), 404);

        $pivot = StudentTournament::query()
            ->whereKey($studentTournament)
            ->where('tournament_id', $tournament->id)
            ->first();

        abort_unless($pivot, 404);
        abort_unless($this->canViewOnlineKataVideo($user, $tournament, (int) $pivot->student_id), 403);

        $path = $this->onlineKataVideoPath($pivot, $round);

        return app(ProtectedMedia::class)->response($path);
    }

    public function attachStudents(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        StudentTournamentListAssignmentService $listAssignment,
    ): JsonResponse {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        abort_if($this->canManageChampionships($request->user()), 403);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer'],
            'list_tournament_id' => ['nullable', 'integer', 'exists:list_tournaments,id'],
        ]);

        $listTournamentId = $data['list_tournament_id'] ?? null;
        $listTournament = null;

        if ($listTournamentId) {
            $listTournament = ListTournament::query()
                ->whereKey($listTournamentId)
                ->where('tournament_id', $tournament->id)
                ->first();

            abort_unless($listTournament, 422);
        }

        $students = app(TournamentStudentEligibility::class)->available($request->user(), $tournament, false)
            ->whereIn('id', $data['student_ids'])
            ->get();
        abort_unless($students->count() === count(array_unique($data['student_ids'])), 422, __('lists.students'));

        $attached = DB::transaction(function () use ($request, $tournament, $students, $listTournament, $listAssignment): array {
            $attached = [];

            foreach ($students as $student) {
                if ((int) $tournament->tournament_type === Tournament::KUMITE
                    && StudentTournament::query()
                        ->where('tournament_id', $tournament->id)
                        ->where('student_id', $student->id)
                        ->exists()) {
                    continue;
                }

                $studentTournament = StudentTournament::query()->firstOrCreate([
                    'tournament_id' => $tournament->id,
                    'student_id' => $student->id,
                ]);

                $assignedList = $listTournament
                    ? $listAssignment->assignToList($studentTournament, $student, $listTournament)
                    : $listAssignment->assignToBestList($studentTournament, $student, $tournament);

                $attached[] = [
                    'student_id' => (int) $student->id,
                    'student_tournament_id' => (int) $studentTournament->id,
                    'list_tournament_id' => (int) $assignedList->id,
                ];
            }

            if ($attached !== []) {
                $this->writeTournamentActivity(
                    $request->user(),
                    'Ученики прикреплены к турниру',
                    'tournament.students.attached',
                    Tournament::class,
                    $tournament->id,
                    [
                        'tournament' => $this->tournamentSnapshot($tournament),
                        'students' => $attached,
                    ]
                );
            }

            return $attached;
        });

        return $this->showTournament($request, $championship, $tournament);
    }

    public function attachGroupStudents(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        StudentTournamentListAssignmentService $listAssignment,
    ): JsonResponse {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        abort_unless($this->canOrganizationAttachGroupStudents($tournament), 403);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:2', 'max:3'],
            'student_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $students = app(TournamentStudentEligibility::class)->available($request->user(), $tournament, true)
            ->whereIn('id', $data['student_ids'])
            ->get();

        abort_unless($students->count() === count($data['student_ids']), 422, __('lists.students'));
        abort_unless($listAssignment->groupAgesMatch($students, $tournament), 422, __('lists.group_age'));

        $alreadyAttached = TournamentStudentList::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->whereHas('listTournament', fn ($query) => $query
                ->where('tournament_id', $tournament->id)
                ->whereHas('templateStudentList', fn ($listQuery) => $listQuery
                    ->where('list_type', TemplateStudentList::KATA)
                    ->where('kata_type', TemplateStudentList::GROUP)))
            ->exists();

        abort_unless(! $alreadyAttached, 422, 'Один из учеников уже прикреплен в групповое ката.');

        $groupId = (string) Str::uuid();
        $attached = DB::transaction(function () use ($request, $tournament, $students, $listAssignment, $groupId): array {
            $tournament = Tournament::lockForUpdate()->findOrFail($tournament->id);
            $available = app(TournamentStudentEligibility::class)->available($request->user(), $tournament, true)->whereIn('id', $students->pluck('id'))->lockForUpdate()->get();
            abort_unless($available->count() === $students->count(), 422, __('lists.students'));
            abort_unless($listAssignment->groupAgesMatch($available, $tournament), 422, __('lists.group_age'));
            $target = $listAssignment->bestGroupList($tournament, $available);
            $attached = [];

            foreach ($available as $student) {
                $studentTournament = StudentTournament::query()->firstOrCreate([
                    'tournament_id' => $tournament->id,
                    'student_id' => $student->id,
                ]);

                $assignedList = $listAssignment->assignToList($studentTournament, $student, $target, $groupId);

                $attached[] = [
                    'student_id' => (int) $student->id,
                    'student_tournament_id' => (int) $studentTournament->id,
                    'list_tournament_id' => (int) $assignedList->id,
                    'group_id' => $groupId,
                ];
            }

            $this->writeTournamentActivity(
                $request->user(),
                'Группа учеников прикреплена к турниру',
                'tournament.group_students.attached',
                Tournament::class,
                $tournament->id,
                [
                    'tournament' => $this->tournamentSnapshot($tournament),
                    'students' => $attached,
                ]
            );

            return $attached;
        });

        return $this->showTournament($request, $championship, $tournament);
    }

    public function detachStudent(Request $request, Championship $championship, Tournament $tournament, int $studentTournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        $data = $request->validate(['membership_id' => ['nullable', 'integer']]);
        $entry = StudentTournament::where('tournament_id', $tournament->id)->findOrFail($studentTournament);
        app(TournamentApplications::class)->detach($entry, $data['membership_id'] ?? null, $request->user());

        return $this->showTournament($request, $championship, $tournament);
    }

    public function updateStudent(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        int $studentTournament,
    ): JsonResponse {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $data = $request->validate([
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:300'],
            'is_success_weight' => ['sometimes', 'boolean'],
            'membership_id' => ['nullable', 'integer'],
        ]);

        $pivot = StudentTournament::query()
            ->whereKey($studentTournament)
            ->where('tournament_id', $tournament->id)
            ->first();

        abort_unless($pivot, 404);

        if (array_key_exists('is_success_weight', $data)) {
            abort_unless((int) $tournament->tournament_type === Tournament::KUMITE, 422);
        }

        DB::transaction(function () use ($request, $tournament, $pivot, $data): void {
            Tournament::lockForUpdate()->findOrFail($tournament->id);
            $student = User::query()->lockForUpdate()->findOrFail($pivot->student_id);
            $old = [
                'weight' => $student->weight,
                'is_success_weight' => (bool) $pivot->is_success_weight,
                'list_tournament_id' => $pivot->list_tournament_id,
            ];

            $changed = [];

            if (array_key_exists('weight', $data)) {
                $student->forceFill(['weight' => $data['weight']])->save();

                app(TournamentApplications::class)->reassignWeight($pivot, $student, $tournament, $data['membership_id'] ?? null, $request->user());
                $pivot->refresh();
                $changed['weight'] = $student->weight;
                $changed['list_tournament_id'] = $pivot->list_tournament_id;
            }

            if (array_key_exists('is_success_weight', $data)) {
                $pivot->forceFill(['is_success_weight' => (bool) $data['is_success_weight']])->save();
                $changed['is_success_weight'] = (bool) $pivot->is_success_weight;
            }

            if ($changed === []) {
                return;
            }

            $this->writeTournamentActivity(
                $request->user(),
                'Данные ученика в турнире обновлены',
                'tournament.student.updated',
                Tournament::class,
                $tournament->id,
                [
                    'tournament' => $this->tournamentSnapshot($tournament),
                    'student_id' => $student->id,
                    'student_tournament_id' => $pivot->id,
                    'old' => $old,
                    'new' => $changed,
                ]
            );
        });

        return $this->showTournament($request, $championship, $tournament);
    }

    public function moveStudentList(Request $request, Championship $championship, Tournament $tournament, int $studentTournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        $data = $request->validate(['membership_id' => ['nullable', 'integer'], 'list_tournament_id' => ['required', 'integer']]);
        $entry = StudentTournament::where('tournament_id', $tournament->id)->findOrFail($studentTournament);
        app(TournamentApplications::class)->move($entry, $data['membership_id'] ?? null, $data['list_tournament_id'], $request->user());

        return $this->showTournament($request, $championship, $tournament);
    }

    private function onlineKataVideoPath(StudentTournament $application, string $round): ?string
    {
        $path = $round === 'first'
            ? ($application->online_kata_first_round_video_path ?: $application->online_kata_video_path)
            : $application->online_kata_second_round_video_path;

        if (! $path) {
            return null;
        }

        return preg_replace('#^/?storage/#', '', ltrim($path, '/'));
    }

    private function canViewOnlineKataVideo(User $user, Tournament $tournament, int $studentId): bool
    {
        if ($this->userHasRole($user, 'Student')) {
            return (int) $user->id === $studentId && app(StudentTournamentEnrollment::class)->admitted($user, $tournament);
        }

        if ($this->userHasRole($user, 'Admin')) {
            return true;
        }

        if ((int) $tournament->organization_id === (int) $this->organizationId($user)
            && ($this->userHasRole($user, 'Organization') || $this->userHasRole($user, 'Secretary'))) {
            return true;
        }

        if ($this->userHasRole($user, 'Judge')) {
            return JudgeKataAccess::views($user, $tournament);
        }

        return $this->userHasRole($user, 'Coach')
            && User::query()
                ->whereKey($studentId)
                ->where('coach_id', $user->id)
                ->exists();
    }
}
