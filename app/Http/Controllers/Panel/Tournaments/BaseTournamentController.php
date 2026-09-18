<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\ExternalForm;
use App\Models\Region;
use App\Models\Scale;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Students\StudentDocumentStatus;
use App\Services\Tournaments\PanelTournamentVisibility;
use App\Services\Tournaments\StudentTournamentEnrollment;
use App\Services\Tournaments\TournamentAge;
use App\Services\Tournaments\TournamentLifecycle;
use App\Services\Tournaments\TournamentListProgressService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BaseTournamentController extends Controller
{
    protected function visibleChampionships(User $user): Builder
    {
        return app(PanelTournamentVisibility::class)->championships($user);
    }

    protected function visibleTournaments(User $user): Builder
    {
        return app(PanelTournamentVisibility::class)->tournaments($user);
    }

    protected function championshipStats(User $user): array
    {
        $base = $this->visibleChampionships($user);
        $today = now()->toDateString();

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where(function (Builder $query) use ($today): void {
                $query->whereDoesntHave('tournaments')
                    ->orWhereHas('tournaments', fn (Builder $query) => $query->whereDate('date_finish', '>=', $today));
            })->count(),
            'completed' => (clone $base)
                ->whereHas('tournaments')
                ->whereDoesntHave('tournaments', fn (Builder $query) => $query->whereDate('date_finish', '>=', $today))
                ->count(),
            'this_month' => (clone $base)->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
    }

    protected function tournamentStats(User $user, Championship $championship): array
    {
        $base = $this->visibleTournaments($user)->where('championship_id', $championship->id);
        $today = now()->toDateString();

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->whereDate('date_finish', '>=', $today)->count(),
            'completed' => (clone $base)->whereDate('date_finish', '<', $today)->count(),
            'this_month' => (clone $base)->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
    }

    protected function regions(User $user, ?Championship $championship = null): array
    {
        $query = $this->visibleTournaments($user)->whereNotNull('region_id');

        if ($championship) {
            $query->where('championship_id', $championship->id);
        }

        $regionIds = $query->distinct()->pluck('region_id');

        return Region::query()
            ->select(['id', 'name'])
            ->whereIn('id', $regionIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Region $region) => ['id' => $region->id, 'name' => $region->name])
            ->all();
    }

    protected function allRegions(): array
    {
        return Region::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Region $region) => ['id' => $region->id, 'name' => $region->name])
            ->all();
    }

    protected function allScales(): array
    {
        return Scale::query()
            ->select(['id', 'name', 'slug'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Scale $scale) => [
                'id' => $scale->id,
                'name' => in_array($scale->slug, [Scale::CLOSED_CLUB, Scale::INTERCLUB], true)
                    ? $scale->name.' ('.__('exports.non_ranking').')'
                    : $scale->name,
            ])
            ->all();
    }

    protected function formatChampionship(User $user, Championship $championship): array
    {
        $totalCount = $championship->tournaments_count ?? $championship->tournaments()->count();
        $activeCount = $championship->active_tournaments_count ?? $championship->tournaments()
            ->whereDate('date_finish', '>=', now()->toDateString())
            ->count();

        return [
            'id' => $championship->id,
            'name' => $championship->name,
            'cover' => $this->assetUrl($championship->banner),
            'tournaments_count' => $totalCount,
            'active_tournaments_count' => $activeCount,
            'completed_tournaments_count' => $championship->completed_tournaments_count ?? 0,
            'status' => $totalCount === 0 || $activeCount > 0 ? 'active' : 'completed',
            'can_manage' => $this->canManageChampionship($user, $championship),
            'can_delete_forms' => $this->canManageChampionship($user, $championship) && ($totalCount === 0 || $activeCount > 0),
        ];
    }

    protected function formatTournament(User $user, Tournament $tournament): array
    {
        $commission = $tournament->date_commission ? Carbon::parse($tournament->date_commission) : null;
        $date = $tournament->date ? Carbon::parse($tournament->date) : null;
        $finish = $tournament->date_finish ? Carbon::parse($tournament->date_finish) : null;
        $clubs = $tournament->treners
            ? $tournament->treners->pluck('club')->filter()->unique()->values()
            : collect();

        return [
            'id' => $tournament->id,
            'championship_id' => $tournament->championship_id,
            'name' => $tournament->name,
            'region' => $tournament->region?->name,
            'scale' => $tournament->scale?->name,
            'address' => $tournament->address,
            'date_commission' => $commission?->toDateTimeString(),
            'date_commission_label' => $commission?->format('d.m.Y H:i'),
            'date' => $date?->toDateString(),
            'date_finish' => $finish?->toDateString(),
            'date_label' => $date?->format('d.m.Y') ?? '',
            'date_finish_label' => $finish?->format('d.m.Y') ?? '',
            'status' => TournamentLifecycle::active($tournament) ? 'active' : 'completed',
            'price' => $tournament->price,
            'price_label' => $tournament->price !== null ? number_format((float) $tournament->price, 0, ',', ' ').' ₽' : '',
            'is_owner' => (int) $tournament->organization_id === (int) $this->organizationId($user),
            'can_delete' => TournamentLifecycle::owns($user, $tournament),
            'can_manage' => $this->canManageTournament($user, $tournament),
            'can_generate_all_brackets' => $this->canGenerateAllBrackets($user, $tournament),
            'students_count' => $tournament->students_count ?? 0,
            'trainers_count' => $tournament->treners_count ?? 0,
            'clubs' => $clubs,
            'more_clubs_count' => max(0, $clubs->count() - 3),
            'cover' => null,
        ];
    }

    protected function formatTournamentDetails(User $user, Tournament $tournament): array
    {
        $result = array_merge($this->formatTournament($user, $tournament), [
            'region_id' => $tournament->region_id,
            'scale_id' => $tournament->scale_id,
            'tournament_type' => (int) $tournament->tournament_type,
            'tournament_type_kata' => $tournament->tournament_type_kata ? (int) $tournament->tournament_type_kata : null,
            'type_label' => $this->tournamentTypeLabel($tournament),
            'is_online_kata' => (bool) $tournament->is_online_kata,
            'accepts_organization_applications' => (bool) $tournament->accepts_organization_applications,
            'can_attach_students' => false,
            'can_attach_group_students' => $this->canManageTournament($user, $tournament) && $this->canOrganizationAttachGroupStudents($tournament),
            'age_from' => $tournament->age_from,
            'age_to' => $tournament->age_to,
            'tatami' => $tournament->tatami,
            'KY_up_to_8' => (bool) $tournament->KY_up_to_8,
            'KY_from_8' => (bool) $tournament->KY_from_8,
            'fight_for_third_place' => (bool) $tournament->fight_for_third_place,
            'chief_judge' => $tournament->chief_judge,
            'chief_secretary' => $tournament->chief_secretary,
            'date_commission_input' => $tournament->date_commission ? Carbon::parse($tournament->date_commission)->format('Y-m-d\TH:i') : '',
            'date_input' => $tournament->date ? Carbon::parse($tournament->date)->format('Y-m-d') : '',
            'date_finish_input' => $tournament->date_finish ? Carbon::parse($tournament->date_finish)->format('Y-m-d') : '',
            'can_manage' => $this->canManageTournament($user, $tournament),
            'downloads' => [
                'brackets' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/brackets"),
                'kata_tables' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/kata-tables"),
                'kumite_protocols' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/kumite-protocols"),
                'kata_protocols' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/kata-protocols"),
                'results' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/results"),
                'certificate' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/certificate"),
                'lists_excel' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/lists-excel"),
                'lists_pdf' => url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/downloads/lists-pdf"),
            ],
            'documents' => [
                ['key' => 'regulation_document', 'label' => __('exports.regulation'), 'url' => $this->assetUrl($tournament->regulation_document)],
                ['key' => 'application_document', 'label' => __('exports.application'), 'url' => $this->assetUrl($tournament->application_document)],
                ['key' => 'logo_report', 'label' => __('exports.report_logo'), 'url' => $this->assetUrl($tournament->logo_report)],
            ],
        ]);
        if ($user->hasProjectRole('Student')) {
            $result['self_enrollment'] = app(StudentTournamentEnrollment::class)->capabilities($user, $tournament);
            $result['downloads'] = array_intersect_key($result['downloads'], array_flip(['lists_excel', 'lists_pdf']));
            $result['documents'] = array_values(array_filter($result['documents'], fn ($document) => $document['key'] !== 'logo_report'));
        }

        return $result;
    }

    protected function tournamentDetail(Request $request, Tournament $tournament): array
    {
        $parts = array_intersect(explode(',', $request->string('parts', 'students,coaches,lists')->toString()), ['students', 'coaches', 'lists']);
        $detail = [];
        if (in_array('students', $parts, true)) {
            $detail['students'] = $this->tournamentStudents($request, $tournament);
        }
        if (in_array('coaches', $parts, true)) {
            $detail['coaches'] = $this->tournamentCoaches($tournament);
            if ($request->user()->hasProjectRole('Student')) {
                $detail['coaches'] = array_map(fn ($coach) => array_diff_key($coach, ['email' => true]), $detail['coaches']);
            }
        }
        if (in_array('lists', $parts, true)) {
            $detail['lists'] = $this->tournamentLists($tournament);
        }

        return $detail;
    }

    protected function showTournament(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeChampionship($request->user(), $championship);
        abort_unless(
            $this->visibleTournaments($request->user())
                ->whereKey($tournament->id)
                ->where('championship_id', $championship->id)
                ->exists(),
            403
        );

        $championship->loadCount([
            'tournaments',
            'tournaments as active_tournaments_count' => fn (Builder $query) => $query->whereDate('date_finish', '>=', now()->toDateString()),
            'tournaments as completed_tournaments_count' => fn (Builder $query) => $query->whereDate('date_finish', '<', now()->toDateString()),
        ]);

        $tournament->load(['region:id,name', 'scale:id,name', 'treners:id,club'])
            ->loadCount(['students', 'treners']);

        return response()->json([
            'championship' => $this->formatChampionship($request->user(), $championship),
            'tournament' => $this->formatTournamentDetails($request->user(), $tournament),
            'detail' => $this->tournamentDetail($request, $tournament),
            ...($request->boolean('metadata', true) ? ['create_options' => [
                'regions' => $this->allRegions(),
                'scales' => $this->allScales(),
            ]] : []),
        ]);
    }

    protected function tournamentStudents(Request $request, Tournament $tournament): array
    {
        $perPage = max(10, min(30, (int) $request->integer('per_page', 10)));
        $query = DB::table('student_tournaments as st')
            ->join('users as u', 'u.id', '=', 'st.student_id')
            ->leftJoin('users as c', fn ($join) => $join->on('c.id', '=', 'u.coach_id')->whereNull('c.deleted_at'))
            ->leftJoin('list_tournaments as lt', 'lt.id', '=', 'st.list_tournament_id')
            ->leftJoin('template_student_lists as tsl', 'tsl.id', '=', 'lt.template_student_list_id')
            ->leftJoin('education_klass_categories as first_cat', 'first_cat.id', '=', DB::raw('COALESCE(st.online_kata_first_round_category_id, st.education_klass_category_id)'))
            ->leftJoin('education_klass_categories as second_cat', 'second_cat.id', '=', 'st.online_kata_second_round_category_id')
            ->where('st.tournament_id', $tournament->id)
            ->whereNull('u.deleted_at')
            ->when($request->filled('coach_id'), fn ($query) => $query->where('u.coach_id', $request->integer('coach_id')))
            ->when($request->filled('list_id'), fn ($query) => $query->whereExists(fn ($members) => $members->selectRaw('1')->from('tournament_student_lists as membership')->whereColumn('membership.student_id', 'st.student_id')->where('membership.list_tournament_id', $request->integer('list_id'))))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(function ($query) use ($search): void {
                    $query->where('u.first_name', 'like', "%{$search}%")
                        ->orWhere('u.last_name', 'like', "%{$search}%");
                });
            })
            ->select([
                'st.id as pivot_id',
                'st.list_tournament_id',
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
                'c.club as coach_club',
                'u.is_success_passport',
                'u.is_success_brand',
                'u.is_success_insurance',
                'u.insurance_close_date',
                'u.is_iko_card_included_check',
                'u.is_success_iko_card',
                'u.is_certificate_included_check',
                'u.is_success_certificate',
                DB::raw("TRIM(CONCAT(COALESCE(c.last_name,''), ' ', COALESCE(c.first_name,''))) as coach_name"),
                'tsl.name as list_name',
                'first_cat.name as first_round_category',
                'second_cat.name as second_round_category',
            ])
            ->orderBy('u.last_name')
            ->orderBy('u.first_name');

        $paginator = $query->paginate($perPage);
        $memberships = TournamentStudentList::query()
            ->whereIn('student_id', $paginator->getCollection()->pluck('id'))
            ->whereHas('listTournament', fn ($q) => $q->where('tournament_id', $tournament->id))
            ->with(['listTournament' => fn ($q) => $q->with('templateStudentList')->withCount(['pools', 'kataPools'])])
            ->get()->groupBy('student_id');
        $viewerIsStudent = $request->user()->hasProjectRole('Student');

        return [
            'data' => $paginator->getCollection()->map(function ($student) use ($tournament, $memberships, $request, $viewerIsStudent): array {
                $documentsStatus = $this->studentDocumentsStatus($student, $tournament);
                $private = ! $viewerIsStudent || (int) $student->id === (int) $request->user()->id;

                return [
                    'pivot_id' => $student->pivot_id,
                    'memberships' => ($memberships[$student->id] ?? collect())->map(fn ($member) => [
                        'id' => $member->id, 'list_id' => $member->list_tournament_id, 'name' => $member->listTournament->templateStudentList?->name,
                        'group_id' => $member->group_id, 'locked' => $member->listTournament->pools_count + $member->listTournament->kata_pools_count > 0,
                    ])->values(),
                    'id' => $student->id,
                    'list_tournament_id' => $student->list_tournament_id,
                    'name' => trim($student->last_name.' '.$student->first_name),
                    'avatar' => $this->assetUrl($student->avatar),
                    'age' => TournamentAge::onCommissionDay($student->birthday, $tournament),
                    'weight' => $student->weight,
                    'rang' => $student->rang,
                    'club' => $student->coach_club,
                    'coach_name' => $student->coach_name ?: '—',
                    'list_name' => $student->list_name ?: '—',
                    'documents_ok' => $documentsStatus['ok'],
                    'documents_status_key' => $private ? $documentsStatus['key'] : ($documentsStatus['ok'] ? 'documentStatusOk' : 'notConfirmed'),
                    'documents_status_context' => $private ? $documentsStatus['context'] : [],
                    'weight_confirmed' => (bool) $student->is_success_weight,
                    'first_round_category' => $student->first_round_category ?: __('exports.not_selected'),
                    'first_round_video_uploaded' => (bool) ($student->online_kata_first_round_video_path ?: $student->online_kata_video_path),
                    'first_round_video' => $private ? $this->onlineKataVideoUrl($tournament, (int) $student->pivot_id, 'first', $student->online_kata_first_round_video_path ?: $student->online_kata_video_path) : null,
                    'second_round_category' => $student->second_round_category ?: __('exports.not_selected'),
                    'second_round_video_uploaded' => (bool) $student->online_kata_second_round_video_path,
                    'second_round_video' => $private ? $this->onlineKataVideoUrl($tournament, (int) $student->pivot_id, 'second', $student->online_kata_second_round_video_path) : null,
                ];
            })->values(),
            'meta' => $this->meta($paginator),
        ];
    }

    protected function tournamentCoaches(Tournament $tournament): array
    {
        return DB::table('tournament_treners as tt')
            ->join('users as u', 'u.id', '=', 'tt.trener_id')
            ->where('tt.tournament_id', $tournament->id)
            ->whereNull('u.deleted_at')
            ->select(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.club'])
            ->orderBy('u.last_name')
            ->get()
            ->map(fn ($coach) => [
                'id' => $coach->id,
                'name' => trim($coach->last_name.' '.$coach->first_name),
                'email' => $coach->email,
                'club' => $coach->club,
            ])
            ->all();
    }

    protected function tournamentLists(Tournament $tournament): array
    {
        $useKataPools = (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;

        $lists = DB::table('list_tournaments as lt')
            ->join('template_student_lists as tsl', 'tsl.id', '=', 'lt.template_student_list_id')
            ->leftJoin('tournament_student_lists as tsl_students', 'tsl_students.list_tournament_id', '=', 'lt.id')
            ->where('lt.tournament_id', $tournament->id)
            ->groupBy('lt.id', 'lt.tatami', 'tsl.id', 'tsl.name', 'tsl.list_type', 'tsl.kata_type', 'tsl.age_from', 'tsl.age_to', 'tsl.weight_from', 'tsl.weight_to', 'tsl.rang_from', 'tsl.rang_to', 'tsl.gender')
            ->orderBy('tsl.age_from')
            ->orderByRaw("CASE WHEN tsl.gender IN ('m') THEN 0 WHEN tsl.gender IN ('f') THEN 1 ELSE 2 END")
            ->orderBy('tsl.weight_from')
            ->select([
                'lt.id',
                'lt.tatami',
                'tsl.name',
                'tsl.list_type',
                'tsl.kata_type',
                'tsl.age_from',
                'tsl.age_to',
                'tsl.weight_from',
                'tsl.weight_to',
                'tsl.rang_from',
                'tsl.rang_to',
                'tsl.gender',
                DB::raw('COUNT(DISTINCT tsl_students.id) as students_count'),
            ])
            ->get();

        $listIds = $lists->pluck('id');

        $progressByList = app(TournamentListProgressService::class)->statsFor($tournament, $listIds);

        $participantsByList = DB::table('tournament_student_lists as tsl_students')
            ->join('users', 'users.id', '=', 'tsl_students.student_id')
            ->whereIn('tsl_students.list_tournament_id', $listIds)
            ->orderBy('tsl_students.created_at')
            ->orderBy('tsl_students.id')
            ->select([
                'tsl_students.list_tournament_id',
                'users.first_name',
                'users.last_name',
            ])
            ->get()
            ->groupBy('list_tournament_id');

        return $lists
            ->map(function ($list) use ($progressByList, $participantsByList, $useKataPools) {
                $progress = $progressByList[(int) $list->id] ?? [
                    'generated_count' => 0,
                    'total_count' => 0,
                    'completed_count' => 0,
                    'completion_percent' => 0,
                ];
                $participantNames = ($participantsByList->get($list->id) ?? collect())
                    ->map(fn ($student) => trim(($student->last_name ?? '').' '.($student->first_name ?? '')))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $list->id,
                    'name' => $list->name,
                    'type' => $list->list_type,
                    'kata_type' => $list->kata_type,
                    'age' => $list->age_from.'–'.$list->age_to,
                    'weight' => $list->weight_from !== null || $list->weight_to !== null ? ($list->weight_from ?? 0).'–'.($list->weight_to ?? 0) : '—',
                    'rank' => $list->rang_from !== null || $list->rang_to !== null ? ($list->rang_from ?? 0).'–'.($list->rang_to ?? 0) : '—',
                    'gender' => $list->gender,
                    'tatami' => $list->tatami,
                    'students_count' => (int) $list->students_count,
                    'participant_names' => $participantNames,
                    'pools_count' => ! $useKataPools ? $progress['total_count'] : 0,
                    'kata_pools_count' => $useKataPools ? $progress['total_count'] : 0,
                    'generated_count' => $progress['generated_count'],
                    'fillable_count' => $progress['total_count'],
                    'completed_count' => $progress['completed_count'],
                    'completion_percent' => $progress['completion_percent'],
                    'has_generated' => $progress['total_count'] > 0,
                ];
            })
            ->all();
    }

    protected function organizationStudentsQuery(User $user): Builder
    {
        $organizationId = $this->organizationId($user);
        $coachIds = User::query()->role('Coach')->where('organization_id', $organizationId)->pluck('id');

        return User::query()
            ->role('Student')
            ->whereIn('coach_id', $coachIds);
    }

    protected function studentDocumentsOk(object $student, Tournament $tournament): bool
    {
        return $this->studentDocumentsStatus($student, $tournament)['ok'];
    }

    protected function studentDocumentsStatus(object $student, Tournament $tournament): array
    {
        return app(StudentDocumentStatus::class)->evaluate($student, $tournament->date_finish);
    }

    protected function tournamentTypeLabel(Tournament $tournament): string
    {
        if ((int) $tournament->tournament_type === Tournament::KUMITE) {
            return __('exports.kumite');
        }

        return (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM
            ? __('exports.kata_points')
            : __('exports.kata_flags');
    }

    protected function formatExternalForm(ExternalForm $form): array
    {
        $participants = collect($form->data['participants'] ?? []);
        $linkedStudents = DB::table('external_form_students')->where('external_form_id', $form->id)->pluck('user_id', 'row_id');
        $clubs = $participants
            ->pluck('club')
            ->filter()
            ->unique()
            ->values();

        return [
            'id' => $form->id,
            'organization_name' => $form->organization_name,
            'status' => $form->status,
            'participants_count' => $participants->count(),
            'confirmed_count' => $participants->filter(fn ($participant) => isset($linkedStudents[$participant['row_id'] ?? '']))->count(),
            'editor_url' => '/panel/tournaments/'.$form->championship_id.'/forms/'.$form->id,
            'clubs' => $clubs,
            'more_clubs_count' => max(0, $clubs->count() - 3),
            'url' => url('/external-form/'.$form->token),
            'created_at' => $form->created_at?->format('d.m.Y'),
        ];
    }

    protected function authorizeChampionship(User $user, Championship $championship): void
    {
        abort_unless($this->visibleChampionships($user)->whereKey($championship->id)->exists(), 403);
    }

    protected function authorizeChampionshipOwner(User $user, Championship $championship): void
    {
        abort_unless($this->canManageChampionship($user, $championship), 403);
    }

    protected function authorizeTournamentManage(User $user, Championship $championship, Tournament $tournament): void
    {
        $this->authorizeChampionship($user, $championship);
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);
        abort_unless($this->canManageTournament($user, $tournament), 403);
    }

    protected function canManageTournament(User $user, Tournament $tournament): bool
    {
        return TournamentLifecycle::canManage($user, $tournament);
    }

    protected function canOrganizationAttachGroupStudents(Tournament $tournament): bool
    {
        return (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM
            && ! (bool) $tournament->is_online_kata;
    }

    protected function canGenerateAllBrackets(User $user, Tournament $tournament): bool
    {
        return $this->canManageTournament($user, $tournament) && TournamentLifecycle::commissionOpen($tournament);
    }

    protected function canManageChampionship(User $user, Championship $championship): bool
    {
        return $this->canManageChampionships($user)
            && (int) $championship->organization_id === (int) $this->organizationId($user);
    }

    protected function canManageChampionships(User $user): bool
    {
        return $this->userHasRole($user, 'Organization') || $this->userHasRole($user, 'Secretary');
    }

    protected function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    protected function onlineKataVideoUrl(Tournament $tournament, int $studentTournamentId, string $round, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return url("/api/panel/tournaments/{$tournament->championship_id}/items/{$tournament->id}/students/{$studentTournamentId}/online-kata-video/{$round}");
    }

    protected function organizationId(User $user): ?int
    {
        if ($this->userHasRole($user, 'Organization')) {
            return $user->id;
        }

        return $user->organization_id;
    }

    protected function userHasRole(User $user, string $role): bool
    {
        return User::query()
            ->whereKey($user->id)
            ->role($role)
            ->exists();
    }

    protected function writeTournamentActivity(User $causer, string $description, string $event, ?string $subjectType, int|string|null $subjectId, array $properties): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'panel',
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $causer->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function championshipSnapshot(Championship $championship): array
    {
        return [
            'id' => $championship->id,
            'name' => $championship->name,
            'organization_id' => $championship->organization_id,
            'banner' => $championship->banner,
            'deleted_at' => $championship->deleted_at?->toDateTimeString(),
        ];
    }

    protected function tournamentSnapshot(Tournament $tournament): array
    {
        return [
            'id' => $tournament->id,
            'championship_id' => $tournament->championship_id,
            'organization_id' => $tournament->organization_id,
            'name' => $tournament->name,
            'region_id' => $tournament->region_id,
            'scale_id' => $tournament->scale_id,
            'age_from' => $tournament->age_from,
            'age_to' => $tournament->age_to,
            'KY_up_to_8' => (bool) $tournament->KY_up_to_8,
            'KY_from_8' => (bool) $tournament->KY_from_8,
            'fight_for_third_place' => (bool) $tournament->fight_for_third_place,
            'tournament_type' => $tournament->tournament_type,
            'tournament_type_kata' => $tournament->tournament_type_kata,
            'is_online_kata' => (bool) $tournament->is_online_kata,
            'tatami' => $tournament->tatami,
            'price' => $tournament->price,
            'date_commission' => $tournament->date_commission,
            'date' => $tournament->date,
            'date_finish' => $tournament->date_finish,
            'address' => $tournament->address,
            'chief_judge' => $tournament->chief_judge,
            'chief_secretary' => $tournament->chief_secretary,
            'regulation_document' => $tournament->regulation_document,
            'application_document' => $tournament->application_document,
            'logo_report' => $tournament->logo_report,
            'deleted_at' => $tournament->deleted_at?->toDateTimeString(),
        ];
    }

    protected function userSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => trim($user->last_name.' '.$user->first_name),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'organization_id' => $user->organization_id,
            'coach_id' => $user->coach_id,
            'club' => $user->club,
        ];
    }

    protected function meta(LengthAwarePaginator $paginator): array
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
