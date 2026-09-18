<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\EducationKlassCategory;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Exports\PanelTasks;
use App\Services\MediaStorage;
use App\Services\Tournaments\Kata\JudgeKataAccess;
use App\Services\Tournaments\Kata\KataAccess;
use App\Services\Tournaments\Kata\KataFinalService;
use App\Services\Tournaments\Kata\KataFinalVideoService;
use App\Services\Tournaments\Kata\KataMutation;
use App\Services\Tournaments\Kata\KataResultService;
use App\Services\Tournaments\Kata\KataScores;
use App\Services\Tournaments\Kata\KataScoreService;
use App\Services\Tournaments\Kata\KataState;
use App\Services\Tournaments\Kata\KataVideoUpload;
use App\Services\Tournaments\StudentTournamentEnrollment;
use App\Services\Tournaments\TournamentDownloadService;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KataTableController extends Controller
{
    private ?bool $canManage = null;

    private ?bool $isJudge = null;

    private ?bool $isCoach = null;

    private ?bool $isStudent = null;

    private ?bool $studentAdmitted = null;

    private ?array $scoreFields = null;

    private ?Collection $applications = null;

    private ?Collection $groupStudents = null;

    public function show(Request $request, Championship $championship, Tournament $tournament, int $list): JsonResponse
    {
        $this->authorizeKataView($request->user(), $championship, $tournament, $list);
        abort_unless((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM, 404);

        $pools = KataPool::query()
            ->with(['student.coach', 'listTournament.templateStudentList'])
            ->where('tournament_id', $tournament->id)
            ->where('list_id', $list)
            ->orderByRaw("CASE WHEN round = 'PRELIMINARY STAGE' THEN 0 ELSE 1 END")
            ->orderBy('participant_number')
            ->get();

        $this->canManage = TournamentLifecycle::canManage($request->user(), $tournament);
        $this->isJudge = $request->user()->hasProjectRole('Judge');
        $this->isCoach = $request->user()->hasProjectRole('Coach');
        $this->isStudent = $request->user()->hasProjectRole('Student');
        $this->studentAdmitted = $this->isStudent && app(StudentTournamentEnrollment::class)->admitted($request->user(), $tournament);
        $this->scoreFields = KataAccess::fields($request->user(), $tournament);
        $listModel = ListTournament::findOrFail($list);
        $studentIds = $pools->flatMap(fn (KataPool $pool) => array_merge($pool->students ?: [], [$pool->student_id]))->filter()->unique();
        $this->groupStudents = User::with('coach')->whereIn('id', $studentIds)->get()->keyBy('id');
        $this->applications = StudentTournament::with(['educationKlassCategory', 'onlineKataFirstRoundCategory', 'onlineKataSecondRoundCategory'])
            ->where('tournament_id', $tournament->id)->where('list_tournament_id', $list)
            ->whereIn('student_id', $studentIds)->orderBy('id')->get()->unique('student_id')->keyBy('student_id');

        return response()->json([
            'revision' => KataState::revision($listModel, $pools),
            'title' => $pools->first()?->listTournament?->templateStudentList?->name ?? __('exports.kata'),
            'finalists_count' => (int) ($listModel->finalists_count ?? 4),
            'can_edit' => $this->canManageKata($request->user(), $tournament) || $this->editableScoreFields($request->user(), $tournament) !== [],
            'can_edit_numbers' => $this->canManageKata($request->user(), $tournament),
            'editable_score_fields' => $this->editableScoreFields($request->user(), $tournament),
            'is_judge' => $this->isJudge,
            'is_online_kata' => (bool) $tournament->is_online_kata,
            'education_categories' => $tournament->is_online_kata
                ? EducationKlassCategory::query()->orderBy('name')->get(['id', 'name'])
                : [],
            'pools' => [
                'pre' => $pools->where('round', 'PRELIMINARY STAGE')->values()->map(fn (KataPool $pool) => $this->formatPool($request->user(), $tournament, $pool))->values(),
                'final' => $pools->where('round', 'FINAL')->values()->map(fn (KataPool $pool) => $this->formatPool($request->user(), $tournament, $pool))->values(),
            ],
        ]);
    }

    public function downloadPdf(Request $request, Championship $championship, Tournament $tournament, int $list)
    {
        abort_if($request->user()->hasProjectRole('Judge'), 403);
        $this->authorizeKataView($request->user(), $championship, $tournament, $list);
        abort_unless((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM, 404);

        if (PanelTasks::shouldDefer($request)) {
            return app(PanelTasks::class)->defer($request, 'kata', ['championship' => $championship->id, 'tournament' => $tournament->id, 'list' => $list]);
        }

        $response = app(TournamentDownloadService::class)->downloadKataProtocols($tournament, false, $list);
        DB::table('activity_log')->insert([
            'log_name' => 'panel', 'event' => 'tournament.kata_table.downloaded', 'description' => 'tournament.kata_table.downloaded',
            'subject_type' => Tournament::class, 'subject_id' => $tournament->id,
            'causer_type' => User::class, 'causer_id' => $request->user()->id,
            'properties' => json_encode(['championship_id' => $championship->id, 'list_id' => $list, 'source' => 'panel', 'locale' => app()->getLocale()]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $response;
    }

    public function updateFinalistsCount(Request $request, Championship $championship, Tournament $tournament, int $list): JsonResponse
    {
        $this->authorizeKataManage($request->user(), $championship, $tournament, $list);
        $data = $request->validate(['count' => ['required', 'integer', 'between:4,8'], 'confirmed' => ['sometimes', 'boolean'], 'revision' => ['required', 'string', 'size:64']]);
        app(KataFinalService::class)->count($request->user(), $tournament, $list, (int) $data['count'], (bool) ($data['confirmed'] ?? false), $data['revision']);

        return $this->show($request, $championship, $tournament, $list);
    }

    public function generateFinal(Request $request, Championship $championship, Tournament $tournament, int $list): JsonResponse
    {
        $this->authorizeKataManage($request->user(), $championship, $tournament, $list);
        $data = $request->validate(['confirmed' => ['sometimes', 'boolean'], 'revision' => ['required', 'string', 'size:64']]);
        app(KataFinalService::class)->generate($request->user(), $tournament, $list, (bool) ($data['confirmed'] ?? false), $data['revision']);

        return $this->show($request, $championship, $tournament, $list);
    }

    public function generateWinners(Request $request, Championship $championship, Tournament $tournament, int $list): JsonResponse
    {
        $this->authorizeKataManage($request->user(), $championship, $tournament, $list);
        $data = $request->validate(['confirmed' => ['sometimes', 'boolean'], 'revision' => ['required', 'string', 'size:64']]);
        app(KataResultService::class)->generate($request->user(), $tournament, $list, (bool) ($data['confirmed'] ?? false), $data['revision']);

        return $this->show($request, $championship, $tournament, $list);
    }

    public function updateNumber(Request $request, Championship $championship, Tournament $tournament, KataPool $kataPool): JsonResponse
    {
        $this->authorizeKataPoolManage($request->user(), $championship, $tournament, $kataPool);
        $data = $request->validate(['participant_number' => ['nullable', 'string', 'max:255']]);
        app(KataMutation::class)->run($request->user(), $tournament->id, $kataPool->list_id, 'tournament.kata.number_updated', ['kata_pool_id' => $kataPool->id], function ($pools) use ($kataPool, $data) {
            $pool = $pools->firstWhere('id', $kataPool->id);
            abort_unless($pool, 404);
            $pool->participant_number = trim((string) ($data['participant_number'] ?? ''));
        });

        return $this->show($request, $championship, $tournament, $kataPool->list_id);
    }

    public function updateScore(Request $request, Championship $championship, Tournament $tournament, KataPool $kataPool): JsonResponse
    {
        $this->authorizeKataPoolView($request->user(), $championship, $tournament, $kataPool);
        $data = $request->validate(['field' => ['required', 'string'], 'value' => ['present', 'nullable'], 'original_value' => ['present', 'nullable', 'string', 'max:255'], 'confirmed' => ['sometimes', 'boolean'], 'revision' => ['nullable', 'string', 'size:64']]);
        app(KataScoreService::class)->update($request->user(), $kataPool, $data['field'], $data['value'], $data['original_value'], (bool) ($data['confirmed'] ?? false), $data['revision'] ?? null);

        return $this->show($request, $championship, $tournament, $kataPool->list_id);
    }

    public function updateFinalVideo(Request $request, Championship $championship, Tournament $tournament, KataPool $kataPool): JsonResponse
    {
        $this->authorizeKataPoolView($request->user(), $championship, $tournament, $kataPool);
        abort_unless((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM, 404);

        $studentId = (int) $request->input('student_id', $kataPool->student_id);
        abort_unless($this->canUploadFinalVideo($request->user(), $tournament, $kataPool, $studentId), 403);

        $data = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'category_id' => ['required', 'integer', 'exists:education_klass_categories,id'],
            'video' => KataVideoUpload::rules(),
        ]);

        $application = app(KataFinalVideoService::class)->replace($request->user(), $kataPool, $studentId, (int) $data['category_id'], $request->file('video'));

        return response()->json([
            'second_round' => $this->formatOnlineKataRoundApplication(
                $tournament,
                $application,
                'second',
                $application->online_kata_second_round_video_path,
                $application->online_kata_second_round_category_id,
                $application->onlineKataSecondRoundCategory?->name
            ),
        ]);
    }

    private function formatPool(User $viewer, Tournament $tournament, KataPool $pool): array
    {
        $editableScoreFields = $this->editableScoreFields($viewer, $tournament);
        $isJudge = $this->isJudge ?? $viewer->hasProjectRole('Judge');

        $groupStudents = [];
        if (! empty($pool->students)) {
            $groupStudents = collect($pool->students)->map(fn ($id) => $this->groupStudents?->get($id))->filter()
                ->map(fn (User $student) => $this->formatKataStudent($viewer, $tournament, $pool, $student))
                ->values()
                ->all();
        }

        return [
            'id' => $pool->id,
            'round' => $pool->round,
            'participant_number' => $pool->participant_number,
            'referee_score' => ! $isJudge || in_array('referee_score', $editableScoreFields, true) ? $this->formatScoreForResponse($pool, 'referee_score') : null,
            'judge1_score' => ! $isJudge || in_array('judge1_score', $editableScoreFields, true) ? $this->formatScoreForResponse($pool, 'judge1_score') : null,
            'judge2_score' => ! $isJudge || in_array('judge2_score', $editableScoreFields, true) ? $this->formatScoreForResponse($pool, 'judge2_score') : null,
            'judge3_score' => ! $isJudge || in_array('judge3_score', $editableScoreFields, true) ? $this->formatScoreForResponse($pool, 'judge3_score') : null,
            'judge4_score' => ! $isJudge || in_array('judge4_score', $editableScoreFields, true) ? $this->formatScoreForResponse($pool, 'judge4_score') : null,
            'total_score' => $isJudge ? null : $this->formatScoreForResponse($pool, 'total_score'),
            'min_score' => $isJudge ? null : $this->formatScoreForResponse($pool, 'min_score'),
            'max_score' => $isJudge ? null : $this->formatScoreForResponse($pool, 'max_score'),
            'rank' => $isJudge ? null : $pool->rank,
            'winner_1' => ! $isJudge && (bool) $pool->winner_1,
            'winner_2' => ! $isJudge && (bool) $pool->winner_2,
            'winner_3' => ! $isJudge && (bool) $pool->winner_3,
            'student' => $pool->student ? $this->formatKataStudent($viewer, $tournament, $pool, $pool->student) : null,
            'group_students' => $groupStudents,
        ];
    }

    private function formatKataStudent(User $viewer, Tournament $tournament, KataPool $pool, User $student): array
    {
        return [
            'id' => $student->id,
            'last_name' => $student->last_name,
            'first_name' => $student->first_name,
            'rang' => $student->rang,
            'club' => $student->coach?->club,
            'coach_name' => trim(($student->coach?->last_name ?? '').' '.($student->coach?->first_name ?? '')),
            'coach_club' => $student->coach?->club,
            'online_application' => $this->canViewOnlineKataVideo($viewer, $tournament, (int) $student->id)
                ? $this->resolveOnlineKataApplication($tournament, $pool, (int) $student->id)
                : null,
            'can_upload_final_video' => $this->canUploadFinalVideo($viewer, $tournament, $pool, (int) $student->id),
            'final_video_uploaded' => $this->hasFinalVideo($pool, (int) $student->id),
        ];
    }

    private function resolveOnlineKataApplication(Tournament $tournament, KataPool $pool, int $studentId): ?array
    {
        $application = $this->application($pool, $studentId);

        if (! $application) {
            return null;
        }

        return [
            'first_round' => $this->formatOnlineKataRoundApplication(
                $tournament,
                $application,
                'first',
                $application->online_kata_first_round_video_path ?: $application->online_kata_video_path,
                $application->online_kata_first_round_category_id ?: $application->education_klass_category_id,
                $application->onlineKataFirstRoundCategory?->name ?: $application->educationKlassCategory?->name
            ),
            'second_round' => $this->formatOnlineKataRoundApplication(
                $tournament,
                $application,
                'second',
                $application->online_kata_second_round_video_path,
                $application->online_kata_second_round_category_id,
                $application->onlineKataSecondRoundCategory?->name
            ),
        ];
    }

    private function formatOnlineKataRoundApplication(
        Tournament $tournament,
        StudentTournament $application,
        string $round,
        ?string $videoPath,
        ?int $categoryId,
        ?string $categoryName,
    ): ?array {
        if (! $videoPath) {
            return null;
        }

        return [
            'video_uploaded' => true,
            'video_url' => "/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/students/{$application->id}/online-kata-video/{$round}",
            'category_id' => $categoryId,
            'category_name' => $categoryName,
        ];
    }

    private function formatScoreForResponse(KataPool $pool, string $field): ?string
    {
        $value = $pool->getRawOriginal($field);
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (in_array($field, KataScores::FIELDS, true)) {
            try {
                return KataScores::normalize($value);
            } catch (ValidationException) {
                return $normalized;
            }
        }

        return $normalized === '' || ! is_numeric($normalized)
            ? $normalized
            : number_format((float) $normalized, 1, '.', '');
    }

    private function canUploadFinalVideo(User $user, Tournament $tournament, KataPool $pool, int $studentId): bool
    {
        if (! (bool) $tournament->is_online_kata || $pool->round !== 'FINAL' || ! $studentId) {
            return false;
        }

        if (! TournamentLifecycle::active($tournament)
            || ! in_array($studentId, array_map('intval', $pool->students ?: [$pool->student_id]), true)
            || ! $this->application($pool, $studentId)) {
            return false;
        }

        if ($this->canManageKata($user, $tournament)) {
            return true;
        }

        if ($this->isStudent ?? $user->hasProjectRole('Student')) {
            return $studentId === (int) $user->id && ($this->studentAdmitted ?? app(StudentTournamentEnrollment::class)->admitted($user, $tournament));
        }

        return ($this->isCoach ?? $user->hasProjectRole('Coach'))
            && ($this->groupStudents !== null
                ? (int) $this->groupStudents->get($studentId)?->coach_id === (int) $user->id
                : User::query()->whereKey($studentId)->where('coach_id', $user->id)->exists());
    }

    private function hasFinalVideo(KataPool $pool, int $studentId): bool
    {
        return (bool) $this->application($pool, $studentId)?->online_kata_second_round_video_path;
    }

    private function application(KataPool $pool, int $studentId): ?StudentTournament
    {
        if ($this->applications !== null) {
            return $this->applications->get($studentId);
        }

        return StudentTournament::with(['educationKlassCategory', 'onlineKataFirstRoundCategory', 'onlineKataSecondRoundCategory'])
            ->where('tournament_id', $pool->tournament_id)->where('list_tournament_id', $pool->list_id)
            ->where('student_id', $studentId)->orderBy('id')->first();
    }

    private function canViewOnlineKataVideo(User $user, Tournament $tournament, int $studentId): bool
    {
        if ($this->isJudge ?? $user->hasProjectRole('Judge')) {
            // The table was authorized once; do not query access for every group member.
            return $this->isJudge === true || JudgeKataAccess::views($user, $tournament);
        }
        if ($this->isStudent ?? $user->hasProjectRole('Student')) {
            return $studentId === (int) $user->id && ($this->studentAdmitted ?? app(StudentTournamentEnrollment::class)->admitted($user, $tournament));
        }
        if ($this->canManageKata($user, $tournament)) {
            return true;
        }

        return ($this->isCoach ?? $user->hasProjectRole('Coach'))
            && ($this->groupStudents !== null
                ? (int) $this->groupStudents->get($studentId)?->coach_id === (int) $user->id
                : User::query()->whereKey($studentId)->where('coach_id', $user->id)->exists());
    }

    private function editableScoreFields(User $user, Tournament $tournament): array
    {
        return $this->scoreFields ?? KataAccess::fields($user, $tournament);
    }

    private function authorizeKataPoolManage(User $user, Championship $championship, Tournament $tournament, KataPool $kataPool): void
    {
        $this->authorizeKataPoolView($user, $championship, $tournament, $kataPool);
        abort_unless($this->canManageKata($user, $tournament), 403);
    }

    private function authorizeKataManage(User $user, Championship $championship, Tournament $tournament, int $list): void
    {
        $this->authorizeKataView($user, $championship, $tournament, $list);
        abort_unless($this->canManageKata($user, $tournament), 403);
    }

    private function authorizeKataPoolView(User $user, Championship $championship, Tournament $tournament, KataPool $kataPool): void
    {
        abort_unless((int) $kataPool->tournament_id === (int) $tournament->id, 404);
        $this->authorizeKataView($user, $championship, $tournament, (int) $kataPool->list_id);
    }

    private function authorizeKataView(User $user, Championship $championship, Tournament $tournament, int $list): void
    {
        app()->setLocale(request()->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
        abort_unless((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM, 404);
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);
        abort_unless(ListTournament::query()->whereKey($list)->where('tournament_id', $tournament->id)->exists(), 404);

        if ($user->hasProjectRole('Student')) {
            abort_unless(app(StudentTournamentEnrollment::class)->admitted($user, $tournament), 403);

            return;
        }

        if (TournamentLifecycle::owns($user, $tournament)) {
            return;
        }

        abort_unless(JudgeKataAccess::views($user, $tournament), 403);
    }

    private function canManageKata(User $user, Tournament $tournament): bool
    {
        return $this->canManage ?? TournamentLifecycle::canManage($user, $tournament);
    }

    private function reportLogoSource(Tournament $tournament): ?string
    {
        return app(MediaStorage::class)->imageDataUri($tournament->logo_report);
    }
}
