<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\EducationKlassCategory;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\OnlineKataApplication;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Exports\MobileTournamentExports;
use App\Services\ProtectedMedia;
use App\Services\Students\StudentDocumentStatus;
use App\Services\Tournaments\BracketService;
use App\Services\Tournaments\CoachTournamentAccess;
use App\Services\Tournaments\CoachTournamentEnrollment;
use App\Services\Tournaments\Kata\KataFinalVideoService;
use App\Services\Tournaments\Kata\KataVideoAccess;
use App\Services\Tournaments\Kata\KataVideoUpload;
use App\Services\Tournaments\MobileTournamentAccess;
use App\Services\Tournaments\OnlineKataPaymentService;
use App\Services\Tournaments\SpectatorFightPath;
use App\Services\Tournaments\StudentTournamentEnrollment;
use App\Services\Tournaments\TournamentAge;
use App\Services\Tournaments\TournamentListProgressService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MobileTournamentItemController extends Controller
{
    private array $coachAssignments = [];

    private array $managementPermissions = [];

    public function show(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);

        return response()->json([
            'tournament' => $this->formatTournament($tournament),
        ]);
    }

    public function document(Request $request, Championship $championship, Tournament $tournament, string $field): Response
    {
        $this->authorizeTournamentView($request->user(), $championship, $tournament);
        abort_unless(in_array($field, ['regulation_document', 'application_document'], true), 404);

        return app(ProtectedMedia::class)->response($tournament->{$field});
    }

    public function students(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);

        $perPage = max(10, min(30, (int) $request->integer('per_page', 10)));

        $query = DB::table('student_tournaments as st')
            ->join('users as u', 'u.id', '=', 'st.student_id')
            ->join('users as c', 'c.id', '=', 'u.coach_id')
            ->where('st.tournament_id', $tournament->id)
            ->whereNull('u.deleted_at')->whereNull('c.deleted_at')
            ->whereIn('st.id', DB::table('student_tournaments')->selectRaw('MIN(id)')->where('tournament_id', $tournament->id)->groupBy('student_id'))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(function ($query) use ($search): void {
                    $query->where('u.first_name', 'like', "%{$search}%")
                        ->orWhere('u.last_name', 'like', "%{$search}%");
                });
            })
            ->select([
                'st.id as pivot_id',
                'st.is_success_weight',
                'st.online_kata_video_path',
                'st.online_kata_first_round_video_path',
                'st.online_kata_second_round_video_path',
                'u.id',
                'u.first_name',
                'u.last_name',
                'u.avatar',
                'u.birthday',
                'u.weight',
                'u.rang',
                'u.coach_id',
                'c.club as coach_club',
                'u.is_success_passport',
                'u.is_success_brand',
                'u.is_success_insurance',
                'u.insurance_close_date',
                'u.is_iko_card_included_check',
                'u.is_success_iko_card',
                'u.is_certificate_included_check',
                'u.is_success_certificate',
                'c.first_name as coach_first_name', 'c.last_name as coach_last_name',
            ])
            ->selectSub(app(CoachTournamentEnrollment::class)->personalMemberships($tournament)->whereColumn('student_id', 'u.id')->selectRaw('COUNT(*)'), 'personal_count')
            ->selectSub(KataPool::query()->where('tournament_id', $tournament->id)->where('round', 'FINAL')->whereColumn('student_id', 'u.id')->selectRaw('COUNT(*)'), 'is_finalist')
            ->orderBy('u.last_name')
            ->orderBy('u.first_name');

        $students = $query->paginate($perPage);

        return response()->json([
            'data' => $students->getCollection()->map(fn (object $student): array => $this->formatStudent($student, $tournament, $coach))->values(),
            'meta' => $this->meta($students),
        ]);
    }

    public function attachStudentOptions(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        abort_unless($request->user()->hasProjectRole('Coach'), 403);
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);
        abort_unless(app(CoachTournamentAccess::class)->canAttach($coach, $tournament), 403);

        $students = app(CoachTournamentEnrollment::class)->options($coach, $tournament, $request->string('search')->toString())
            ->paginate(max(10, min(50, $request->integer('per_page', 20))));

        return response()->json([
            'data' => $students->getCollection()->map(fn (User $student) => $this->formatAttachStudent($student, $tournament))->values(),
            'meta' => $this->meta($students),
        ]);
    }

    public function attachStudents(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeTournamentView($request->user(), $championship, $tournament);
        abort_unless($request->user()->hasProjectRole('Coach'), 403);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:100'],
            'student_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return response()->json(['attached' => app(CoachTournamentEnrollment::class)->attach($request->user(), $tournament, $data['student_ids'])]);
    }

    public function attachOnlineKataStudent(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        OnlineKataPaymentService $payments,
    ): JsonResponse {
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);
        abort_unless($this->canCoachAttachOnlineKata($coach, $tournament), 403);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'online_kata_first_round_category_id' => ['required', 'integer', 'exists:education_klass_categories,id'],
            'video' => KataVideoUpload::rules(),
        ]);

        $student = User::query()
            ->role('Student')
            ->when($coach->projectRoleNames() === ['Student'], fn ($q) => $q->whereKey($coach->id), fn ($q) => $q->where('coach_id', $coach->id))
            ->whereKey((int) $data['student_id'])
            ->first();

        abort_unless($student, 422, __('mobile.own_student'));
        abort_unless($this->studentRankAllowedByTournament($student, $tournament), 422, __('mobile.ineligible'));

        if (app(CoachTournamentEnrollment::class)->personalMemberships($tournament, $student->id)->exists()) {
            throw ValidationException::withMessages([
                'student_id' => __('mobile.already_enrolled'),
            ]);
        }

        $path = $request->file('video')->store('online-kata-videos', 'protected');

        try {
            $payment = $payments->createPayment(
                $tournament,
                $student,
                $coach,
                $path,
                (int) $data['online_kata_first_round_category_id'],
            );
        } catch (\Throwable $e) {
            if (! OnlineKataApplication::where('video_path', $path)->exists()) {
                Storage::disk('protected')->delete($path);
            }

            throw $e;
        }

        return response()->json([
            'payment' => $payment,
            'message' => __('mobile.continue_payment'),
        ]);
    }

    public function coaches(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);

        $perPage = max(10, min(30, (int) $request->integer('per_page', 10)));

        $query = DB::table('tournament_treners as tt')
            ->join('users as u', 'u.id', '=', 'tt.trener_id')
            ->leftJoin('student_tournaments as st', function ($join): void {
                $join->on('st.tournament_id', '=', 'tt.tournament_id')
                    ->whereColumn('st.student_id', '!=', 'u.id');
            })
            ->leftJoin('users as students', function ($join): void {
                $join->on('students.id', '=', 'st.student_id')
                    ->whereColumn('students.coach_id', 'u.id')->whereNull('students.deleted_at');
            })
            ->where('tt.tournament_id', $tournament->id)->whereNull('u.deleted_at')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(function ($query) use ($search): void {
                    $query->where('u.first_name', 'like', "%{$search}%")
                        ->orWhere('u.last_name', 'like', "%{$search}%")
                        ->orWhere('u.club', 'like', "%{$search}%");
                });
            })
            ->groupBy('u.id', 'u.first_name', 'u.last_name', 'u.avatar', 'u.club')
            ->select([
                'u.id',
                'u.first_name',
                'u.last_name',
                'u.avatar',
                'u.club',
                DB::raw('COUNT(DISTINCT students.id) as students_count'),
            ])
            ->orderBy('u.last_name')
            ->orderBy('u.first_name');

        $coaches = $query->paginate($perPage);

        return response()->json([
            'data' => $coaches->getCollection()->map(fn (object $coach): array => [
                'id' => (int) $coach->id,
                'name' => trim($coach->last_name.' '.$coach->first_name),
                'avatar' => $this->assetUrl($coach->avatar),
                'club' => $coach->club ?: '—',
                'students_count' => (int) $coach->students_count,
            ])->values(),
            'meta' => $this->meta($coaches),
        ]);
    }

    public function lists(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);

        $useKataPools = (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;

        $lists = DB::table('list_tournaments as lt')
            ->join('template_student_lists as tsl', 'tsl.id', '=', 'lt.template_student_list_id')
            ->leftJoin('tournament_student_lists as tsl_students', 'tsl_students.list_tournament_id', '=', 'lt.id')
            ->leftJoin('users as list_student', function ($join) {
                $join->on('list_student.id', '=', 'tsl_students.student_id')->whereNull('list_student.deleted_at');
            })
            ->leftJoin('users as list_coach', function ($join) {
                $join->on('list_coach.id', '=', 'list_student.coach_id')->whereNull('list_coach.deleted_at');
            })
            ->where('lt.tournament_id', $tournament->id)
            ->when($request->boolean('generated'), fn ($q) => $q->whereExists(function ($query) use ($tournament, $useKataPools): void {
                $query->selectRaw('1')
                    ->from($useKataPools ? 'kata_pools as generated_items' : 'pools as generated_items')
                    ->whereColumn('generated_items.list_id', 'lt.id')
                    ->where('generated_items.tournament_id', $tournament->id);
            }))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where('tsl.name', 'like', "%{$search}%");
            })
            ->groupBy('lt.id', 'tsl.name', 'tsl.age_from', 'tsl.gender', 'tsl.weight_from')
            ->orderBy('tsl.age_from')
            ->orderByRaw("CASE WHEN tsl.gender IN ('m') THEN 0 WHEN tsl.gender IN ('f') THEN 1 ELSE 2 END")
            ->orderBy('tsl.weight_from')
            ->select([
                'lt.id',
                'tsl.name',
                DB::raw('COUNT(DISTINCT CASE WHEN list_coach.id IS NOT NULL THEN list_student.id END) as students_count'),
            ])
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        $progressByList = app(TournamentListProgressService::class)->statsFor($tournament, $lists->pluck('id'));

        return response()->json([
            'data' => $lists->map(function (object $list) use ($progressByList): array {
                $progress = $progressByList[(int) $list->id] ?? ['completion_percent' => 0];

                return [
                    'id' => (int) $list->id,
                    'name' => $list->name,
                    'students_count' => (int) $list->students_count,
                    'completion_percent' => (int) $progress['completion_percent'],
                    'generated' => ($progress['generated_count'] ?? 0) > 0,
                ];
            })->values(),
            'meta' => $this->meta($lists),
        ]);
    }

    public function bracket(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        ListTournament $listTournament,
        BracketService $brackets
    ): JsonResponse {
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);
        abort_unless((int) $listTournament->tournament_id === (int) $tournament->id, 404);
        $listTournament->loadMissing('templateStudentList');

        $isKataTable = (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;

        return response()->json($isKataTable
            ? $this->kataTablePayload($coach, $tournament, $listTournament)
            : $this->kumiteBracketPayload($tournament, $listTournament, $brackets));
    }

    public function updateKataFinalVideo(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        KataPool $kataPool,
    ): JsonResponse {
        $coach = $request->user();
        $this->authorizeTournamentView($coach, $championship, $tournament);
        abort_unless($this->isOnlinePointKata($tournament), 404);
        abort_unless((int) $kataPool->tournament_id === (int) $tournament->id && $kataPool->round === 'FINAL', 404);

        $kataPool->loadMissing('student.coach');
        abort_unless($kataPool->student && app(MobileTournamentAccess::class)->owns($coach, $kataPool->student), 403);
        abort_unless(app(MobileTournamentAccess::class)->assigned($coach, $tournament), 403);

        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:education_klass_categories,id'],
            'video' => KataVideoUpload::rules(),
        ]);

        $application = app(KataFinalVideoService::class)->replace($coach, $kataPool, (int) $kataPool->student_id, (int) $data['category_id'], $request->file('video'));

        $application->load(['educationKlassCategory', 'onlineKataFirstRoundCategory', 'onlineKataSecondRoundCategory']);

        return response()->json([
            'row' => $this->formatMobileKataPool($coach, $tournament, $kataPool->refresh(), $application),
        ]);
    }

    public function detachStudent(Request $request, Championship $championship, Tournament $tournament, StudentTournament $studentTournament): JsonResponse
    {
        $this->authorizeTournamentView($request->user(), $championship, $tournament);
        app(CoachTournamentEnrollment::class)->detach($request->user(), $tournament, $studentTournament);

        return response()->json(['ok' => true]);
    }

    public function attachSelf(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeTournamentView($request->user(), $championship, $tournament);
        app(StudentTournamentEnrollment::class)->attach($request->user(), $tournament);

        return response()->json(['attached' => [$request->user()->id]]);
    }

    public function detachSelf(Request $request, Championship $championship, Tournament $tournament, int $membership): JsonResponse
    {
        $this->authorizeTournamentView($request->user(), $championship, $tournament);
        app(StudentTournamentEnrollment::class)->detach($request->user(), $tournament, $membership);

        return response()->json(['ok' => true]);
    }

    private function authorizeTournamentView(User $coach, Championship $championship, Tournament $tournament): void
    {
        abort_unless(app(MobileTournamentAccess::class)->assigned($coach, $tournament), 403);
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);
    }

    private function formatTournament(Tournament $tournament): array
    {
        $tournament->loadMissing(['championship:id,name,banner', 'region:id,name', 'scale:id,name']);
        $finish = $tournament->date_finish ? Carbon::parse($tournament->date_finish) : null;
        $isOnlinePointKata = $this->isOnlinePointKata($tournament);
        $coach = request()->user();

        return [
            'id' => $tournament->id,
            'championship_id' => $tournament->championship_id,
            'name' => $tournament->name,
            'championship_name' => $tournament->championship?->name,
            'cover' => $this->assetUrl($tournament->championship?->banner),
            'type' => (int) $tournament->tournament_type === Tournament::KATA ? 'kata' : 'kumite',
            'tables_label' => (int) $tournament->tournament_type === Tournament::KATA ? __('mobile.tables') : __('mobile.pools'),
            'is_online_kata' => (bool) $tournament->is_online_kata,
            'status' => $finish && $finish->lt(now()->startOfDay()) ? 'completed' : 'active',
            'date_label' => $tournament->date ? Carbon::parse($tournament->date)->format('d.m.Y') : '',
            'date_commission_label' => $tournament->date_commission ? Carbon::parse($tournament->date_commission)->format('d.m.Y H:i') : '',
            'date_finish_label' => $finish?->format('d.m.Y H:i') ?? '',
            'region' => $tournament->region?->name,
            'scale' => $tournament->scale?->name,
            'address' => $tournament->address,
            'chief_judge' => $tournament->chief_judge,
            'chief_secretary' => $tournament->chief_secretary,
            'age_from' => $tournament->age_from,
            'age_to' => $tournament->age_to,
            'tatami' => $tournament->tatami,
            'ky_up_to_8' => (bool) $tournament->KY_up_to_8,
            'ky_from_8' => (bool) $tournament->KY_from_8,
            'documents' => collect(['regulation_document', 'application_document'])->filter(fn ($field) => filled($tournament->{$field}))->map(fn ($field) => ['key' => $field, 'url' => "/championships/{$tournament->championship_id}/tournaments/{$tournament->id}/documents/{$field}", 'name' => basename($tournament->{$field})])->values(),
            'price_label' => $tournament->price !== null ? number_format((float) $tournament->price, 0, ',', ' ').' ₽' : '',
            'can_attach_students' => $isOnlinePointKata
                ? ($this->canCoachAttachOnlineKata($coach, $tournament) && ($coach->projectRoleNames() !== ['Student'] || ! app(CoachTournamentEnrollment::class)->personalMemberships($tournament, $coach->id)->exists()))
                : ($coach->projectRoleNames() === ['Student'] ? app(StudentTournamentEnrollment::class)->capabilities($coach, $tournament)['can_attach'] : app(CoachTournamentAccess::class)->canAttach($coach, $tournament)),
            'self_enrollment' => $coach->projectRoleNames() === ['Student'] ? app(StudentTournamentEnrollment::class)->capabilities($coach, $tournament) : null,
            'export_formats' => app(MobileTournamentExports::class)->formats($coach, $tournament),
            'can_detach_students' => $this->canManageStudents($coach, $tournament),
            'requires_online_kata_payment' => $isOnlinePointKata,
            'online_kata_price_label' => $isOnlinePointKata
                ? number_format((float) config('yookassa.online_kata_price', 1000), 0, ',', ' ').' ₽'
                : '',
            'education_categories' => $isOnlinePointKata
                ? EducationKlassCategory::query()->orderBy('name')->get(['id', 'name'])->map(fn (EducationKlassCategory $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                ])->values()
                : [],
        ];
    }

    private function isOnlinePointKata(Tournament $tournament): bool
    {
        return (bool) $tournament->is_online_kata
            && (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;
    }

    private function canCoachAttachOnlineKata(?User $coach, Tournament $tournament): bool
    {
        return $this->isOnlinePointKata($tournament) && $this->canManageStudents($coach, $tournament);
    }

    private function canManageStudents(?User $coach, Tournament $tournament): bool
    {
        return $this->managementPermissions[$tournament->id.':'.$coach?->id] ??= $coach && app(MobileTournamentAccess::class)->manages($coach, $tournament);
    }

    private function formatAttachStudent(User $student, Tournament $tournament): array
    {
        return [
            'id' => (int) $student->id,
            'name' => trim($student->last_name.' '.$student->first_name),
            'avatar' => $this->assetUrl($student->avatar),
            'age' => TournamentAge::onCommissionDay($student->birthday, $tournament),
            'weight' => $student->weight,
            'rang' => $student->rang,
            'club' => $student->coach?->club ?: '—',
        ];
    }

    private function studentRankAllowedByTournament(User $student, Tournament $tournament): bool
    {
        return app(CoachTournamentAccess::class)->rankAllowed($student, $tournament);
    }

    private function formatStudent(object $student, Tournament $tournament, User $coach): array
    {
        $videoOk = filled($student->online_kata_first_round_video_path ?: $student->online_kata_video_path);

        return [
            'pivot_id' => (int) $student->pivot_id,
            'id' => (int) $student->id,
            'name' => trim($student->last_name.' '.$student->first_name),
            'avatar' => $this->assetUrl($student->avatar),
            'age' => TournamentAge::onCommissionDay($student->birthday, $tournament),
            'weight' => $student->weight,
            'rang' => $student->rang,
            'club' => $student->coach_club ?: '—',
            'coach_name' => trim(($student->coach_last_name ?? '').' '.($student->coach_first_name ?? '')) ?: '—',
            'documents_ok' => $this->documentsOk($student, $tournament),
            'video_ok' => $videoOk,
            'first_video_uploaded' => $videoOk,
            'final_video_required' => (bool) $student->is_finalist,
            'final_video_uploaded' => (bool) $student->online_kata_second_round_video_path,
            'show_video_status' => (bool) $tournament->is_online_kata,
            'can_detach' => (int) $student->coach_id === (int) $coach->id
                && $this->canManageStudents($coach, $tournament)
                && (int) $student->personal_count === 1,
        ];
    }

    private function coachAssignedToTournament(User $coach, Tournament $tournament): bool
    {
        return $this->coachAssignments[$tournament->id.':'.$coach->id] ??= DB::table('tournament_treners')
            ->where('tournament_id', $tournament->id)
            ->where('trener_id', $coach->id)
            ->exists();
    }

    private function documentsOk(object $student, Tournament $tournament): bool
    {
        return app(StudentDocumentStatus::class)->evaluate($student, $tournament->date_finish)['ok'];
    }

    private function kumiteBracketPayload(Tournament $tournament, ListTournament $listTournament, BracketService $brackets): array
    {
        $payload = $brackets->bracket($tournament, $listTournament->id);
        $pools = collect($payload['tournament']['pools'] ?? [])
            ->map(fn (array $pool): array => $this->formatMobilePool($pool))
            ->values();

        $roundRobin = $pools->where('type', 'Round Robin')->values();
        $thirdPlace = $pools->firstWhere('type', '3rd');
        $regularPools = $pools
            ->reject(fn (array $pool): bool => in_array($pool['type'], ['3rd', 'Round Robin'], true))
            ->values();

        $rounds = $regularPools
            ->groupBy('round')
            ->sortKeys()
            ->map(function ($roundPools, int|string $round) use ($regularPools): array {
                return [
                    'number' => (int) $round,
                    'title' => app(SpectatorFightPath::class)->stage((int) $round, (int) $regularPools->max('round'), $roundPools->first()['type'] ?? null),
                    'pools' => $roundPools->values(),
                ];
            })
            ->values();

        if ($roundRobin->isNotEmpty()) {
            $rounds = collect([[
                'number' => 1,
                'title' => 'Round Robin',
                'pools' => $roundRobin,
            ]]);
        }

        return [
            'kind' => 'kumite',
            'title' => $listTournament->templateStudentList?->name
                ?? ($payload['tournament']['titleList'] ?? __('mobile.pools')),
            'tatami' => $listTournament->tatami,
            'is_round_robin' => $roundRobin->isNotEmpty(),
            'rounds' => $rounds->values(),
            'third_place' => $thirdPlace,
            'podium' => $this->roundRobinPodium($roundRobin),
        ];
    }

    private function kataTablePayload(User $coach, Tournament $tournament, ListTournament $listTournament): array
    {
        $pools = KataPool::query()
            ->with(['student.coach', 'listTournament.templateStudentList'])
            ->where('tournament_id', $tournament->id)
            ->where('list_id', $listTournament->id)
            ->orderByRaw("CASE WHEN round = 'PRELIMINARY STAGE' THEN 0 ELSE 1 END")
            ->orderBy('participant_number')
            ->get();

        $members = User::query()->select('id', 'coach_id', 'first_name', 'last_name')->with('coach:id,first_name,last_name,club')->whereIn('id', $pools->flatMap(fn ($p) => $p->students ?: [$p->student_id])->filter()->unique())->get()->keyBy('id');

        $applications = StudentTournament::query()
            ->with(['educationKlassCategory', 'onlineKataFirstRoundCategory', 'onlineKataSecondRoundCategory'])
            ->where('tournament_id', $tournament->id)
            ->where(function ($q) use ($listTournament) {
                $q->where('list_tournament_id', $listTournament->id)->orWhereExists(function ($membership) use ($listTournament) {
                    $membership->selectRaw('1')->from('tournament_student_lists')
                        ->whereColumn('tournament_student_lists.student_id', 'student_tournaments.student_id')
                        ->where('list_tournament_id', $listTournament->id);
                });
            })
            ->whereIn('student_id', $pools->pluck('student_id')->filter()->unique()->values())
            ->get()
            ->keyBy(fn (StudentTournament $application): int => (int) $application->student_id);

        $isOnlinePointKata = $this->isOnlinePointKata($tournament);
        $assigned = app(MobileTournamentAccess::class)->assigned($coach, $tournament);
        $formatRow = fn (KataPool $pool): array => $this->formatMobileKataPool(
            $coach,
            $tournament,
            $pool,
            $pool->student_id ? $applications->get((int) $pool->student_id) : null,
            $assigned,
            $members,
        );

        return [
            'kind' => 'kata',
            'title' => $listTournament->templateStudentList?->name
                ?? $pools->first()?->listTournament?->templateStudentList?->name
                ?? __('mobile.tables'),
            'tatami' => $listTournament->tatami,
            'is_online_kata' => $isOnlinePointKata,
            'education_categories' => $isOnlinePointKata
                ? EducationKlassCategory::query()->orderBy('name')->get(['id', 'name'])->map(fn (EducationKlassCategory $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                ])->values()
                : [],
            'rounds' => [
                [
                    'key' => 'pre',
                    'title' => __('mobile.preliminary'),
                    'rows' => $pools
                        ->where('round', 'PRELIMINARY STAGE')
                        ->values()
                        ->map($formatRow)
                        ->values(),
                ],
                [
                    'key' => 'final',
                    'title' => __('mobile.final'),
                    'rows' => $pools
                        ->where('round', 'FINAL')
                        ->values()
                        ->map($formatRow)
                        ->values(),
                ],
            ],
        ];
    }

    private function formatMobilePool(array $pool): array
    {
        return [
            'id' => (int) ($pool['id'] ?? 0),
            'round' => (int) ($pool['round'] ?? 0),
            'position' => (int) ($pool['position_in_round'] ?? 0),
            'type' => $pool['type'] ?? null,
            'fight_number' => $pool['tatami_and_fight_number'] ?? null,
            'winner_id' => isset($pool['winner_id']) ? (int) $pool['winner_id'] : null,
            'winner_id_1rd_robbin' => isset($pool['winner_id_1rd_robbin']) ? (int) $pool['winner_id_1rd_robbin'] : null,
            'winner_id_2rd_robbin' => isset($pool['winner_id_2rd_robbin']) ? (int) $pool['winner_id_2rd_robbin'] : null,
            'winner_id_3rd_robbin' => isset($pool['winner_id_3rd_robbin']) ? (int) $pool['winner_id_3rd_robbin'] : null,
            'student_wazari_count' => (int) ($pool['student_wazari_count'] ?? 0),
            'opponent_wazari_count' => (int) ($pool['opponent_wazari_count'] ?? 0),
            'student_ippon' => (bool) ($pool['student_ippon'] ?? false),
            'opponent_ippon' => (bool) ($pool['opponent_ippon'] ?? false),
            'student_absent' => (bool) ($pool['absent_student'] ?? false),
            'opponent_absent' => (bool) ($pool['absent_opponent'] ?? false),
            'student' => $this->formatMobilePoolParticipant($pool['student'] ?? null),
            'opponent' => $this->formatMobilePoolParticipant($pool['opponent'] ?? null),
        ];
    }

    private function formatMobilePoolParticipant(?array $participant): ?array
    {
        if (! $participant) {
            return null;
        }

        return [
            'id' => (int) ($participant['id'] ?? 0),
            'name' => trim(($participant['last_name'] ?? '').' '.($participant['first_name'] ?? '')),
            'club' => $participant['coach_line'] ?? null,
            'avatar' => $this->assetUrl($participant['avatar'] ?? null),
        ];
    }

    private function formatMobileKataPool(
        User $coach,
        Tournament $tournament,
        KataPool $pool,
        ?StudentTournament $application,
        ?bool $assigned = null,
        ?Collection $members = null,
    ): array {
        $student = $pool->student;
        $team = collect($pool->students ?: [])->map(fn ($id) => $members?->get($id))->filter()->values();
        $isFinal = $pool->round === 'FINAL';
        $assigned ??= app(MobileTournamentAccess::class)->assigned($coach, $tournament);
        $firstRoundVideo = $application?->online_kata_first_round_video_path ?: $application?->online_kata_video_path;
        $secondRoundVideo = $application?->online_kata_second_round_video_path;
        $categoryId = $isFinal
            ? $application?->online_kata_second_round_category_id
            : ($application?->online_kata_first_round_category_id ?: $application?->education_klass_category_id);
        $categoryName = $isFinal
            ? $application?->onlineKataSecondRoundCategory?->name
            : ($application?->onlineKataFirstRoundCategory?->name ?: $application?->educationKlassCategory?->name);

        return [
            'id' => $pool->id,
            'student_id' => $student?->id,
            'round_key' => $isFinal ? 'final' : 'pre',
            'participant_number' => $pool->participant_number,
            'members' => $team->map(fn ($member) => ['id' => $member->id, 'name' => trim($member->last_name.' '.$member->first_name), 'club' => $member->coach?->club, 'coach_name' => trim(($member->coach?->last_name ?? '').' '.($member->coach?->first_name ?? ''))])->all(),
            'name' => $team->isNotEmpty() ? $team->map(fn ($m) => trim($m->last_name.' '.$m->first_name))->implode("\n") : (trim(($student?->last_name ?? '').' '.($student?->first_name ?? '')) ?: '—'),
            'club' => $student?->coach?->club ?: '—',
            'coach_name' => trim(($student?->coach?->last_name ?? '').' '.($student?->coach?->first_name ?? '')) ?: '—',
            'referee_score' => $this->score($pool->referee_score),
            'judge1_score' => $this->score($pool->judge1_score),
            'judge2_score' => $this->score($pool->judge2_score),
            'judge3_score' => $this->score($pool->judge3_score),
            'judge4_score' => $this->score($pool->judge4_score),
            'total_score' => $this->score($pool->total_score),
            'min_score' => $this->score($pool->min_score),
            'max_score' => $this->score($pool->max_score),
            'rank' => $pool->rank,
            'winner_place' => $pool->winner_1 ? 1 : ($pool->winner_2 ? 2 : ($pool->winner_3 ? 3 : null)),
            'category_id' => $categoryId ? (int) $categoryId : null,
            'category_name' => $categoryName,
            'video_uploaded' => (bool) ($isFinal ? $secondRoundVideo : $firstRoundVideo),
            'video_url' => $application && app(KataVideoAccess::class)->canView($coach, $tournament, $student, $assigned)
                ? url("/api/mobile/files/kata/{$pool->id}/{$student->id}") : null,
            'can_update_final_video' => app(KataVideoAccess::class)->updateReason($coach, $tournament, $pool, $student, $application, $assigned) === null,
            'video_update_reason' => app(KataVideoAccess::class)->updateReason($coach, $tournament, $pool, $student, $application, $assigned),
        ];
    }

    private function roundRobinPodium($roundRobin): array
    {
        $first = $roundRobin->first();

        if (! $first) {
            return [];
        }

        $participants = $roundRobin
            ->flatMap(fn (array $pool): array => [$pool['student'], $pool['opponent']])
            ->filter()
            ->keyBy('id');

        return collect([
            1 => $first['winner_id_1rd_robbin'] ?? null,
            2 => $first['winner_id_2rd_robbin'] ?? null,
            3 => $first['winner_id_3rd_robbin'] ?? null,
        ])
            ->filter()
            ->map(fn (int $id, int $place): array => [
                'place' => $place,
                'participant' => $participants->get($id),
            ])
            ->filter(fn (array $row): bool => ! empty($row['participant']))
            ->values()
            ->all();
    }

    private function score(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $score = (string) $value;

        return str_contains($score, '.') ? rtrim(rtrim($score, '0'), '.') : $score;
    }

    private function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem() ?? 0,
            'to' => $paginator->lastItem() ?? 0,
        ];
    }
}
