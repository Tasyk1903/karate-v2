<?php

namespace App\Http\Controllers\Panel;

use App\Exports\TeamSectionExport;
use App\Exports\TrainerStudentsExport;
use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use App\Services\Exports\ExportLabels;
use App\Services\Exports\PanelTasks;
use App\Services\Exports\PdfRenderer;
use App\Services\PanelAccess;
use App\Services\ProtectedMedia;
use App\Services\Team\OrganizationInvitations;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class TeamController extends Controller
{
    private const ROLE_MAP = [
        'judges' => 'Judge',
        'secretaries' => 'Secretary',
        'trainers' => 'Coach',
        'students' => 'Student',
    ];

    public function index(Request $request): JsonResponse
    {
        $section = $this->normalizeSection($request->string('section')->toString());
        abort_unless(in_array($section, PanelAccess::teamSections($request->user()), true), 403);
        $perPage = max(5, min(50, (int) $request->integer('per_page', 10)));

        return response()->json([
            'items' => $this->items($request, $section, $perPage),
            'stats' => $this->stats($request->user()),
        ]);
    }

    public function export(Request $request)
    {
        $section = $this->normalizeSection($request->string('section')->toString());
        abort_unless(in_array($section, PanelAccess::teamSections($request->user()), true), 403);
        if (PanelTasks::shouldDefer($request)) {
            return app(PanelTasks::class)->defer($request, 'team', ['filters' => ['section' => $section] + $request->only(['search', 'sort', 'direction'])]);
        }

        $rows = $section === 'pending'
            ? $this->pendingExportRows($request)
            : tap(
                $this->baseQuery($request->user(), $section)
                    ->when($request->filled('search'), fn (Builder $query) => $this->applySearch($query, $request->string('search')->toString(), $section)),
                fn (Builder $query) => $section === 'students'
                    ? $query->orderBy('created_at')->orderBy('id')
                    : $query->orderBy('last_name')->orderBy('first_name')
            )
                ->lazy(500);

        $exportRows = $rows
            ->values()
            ->map(fn (User|WaitConfirmationInvitation $row, int $index): array => $this->exportRow($row, $section, $index + 1));

        $this->writeTeamExportActivity($request->user(), $section, $exportRows->count(), $request->only(['search', 'sort']));

        return Excel::download(
            new TeamSectionExport($exportRows, $this->exportHeaders($section)),
            'team-'.$section.'.xlsx'
        );
    }

    public function store(Request $request, string $section): JsonResponse
    {
        abort_unless($request->user()->hasProjectRole('Organization'), 403);
        $section = $this->normalizeEditableSection($section);
        $validated = $this->validateMember($request, $section);
        $role = self::ROLE_MAP[$section];
        $orgId = $this->organizationId($request->user());

        abort_if(! $orgId, 403);

        $user = DB::transaction(function () use ($request, $validated, $section, $orgId, $role): User {
            $user = new User;
            $this->fillMember($user, $validated, $section, $orgId);
            $user->password = Hash::make($validated['password']);
            $user->save();

            $this->syncRole($user, $role);
            $this->writeTeamActivity(
                $request->user(),
                __('exports.member_created'),
                'team.member.created',
                User::class,
                $user->id,
                [
                    'section' => $section,
                    'new' => $this->memberSnapshot($user->fresh()),
                ]
            );

            return $user;
        });

        return response()->json([
            'item' => $this->formatUser($user->fresh(), $section),
            'stats' => $this->stats($request->user()),
        ], 201);
    }

    public function update(Request $request, string $section, User $user): JsonResponse
    {
        abort_unless($request->user()->hasProjectRole('Organization'), 403);
        $section = $this->normalizeEditableSection($section);
        $role = self::ROLE_MAP[$section];
        $orgId = $this->organizationId($request->user());

        abort_if(! $orgId || (int) $user->organization_id !== (int) $orgId || ! $this->userHasRole($user, $role), 403);

        $validated = $this->validateMember($request, $section, $user);

        $before = $this->memberSnapshot($user);

        DB::transaction(function () use ($request, $user, $validated, $section, $orgId, $role, $before): void {
            $this->fillMember($user, $validated, $section, $orgId);

            if (! empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            $user->save();
            $this->syncRole($user, $role);

            $this->writeTeamActivity(
                $request->user(),
                __('exports.member_updated'),
                'team.member.updated',
                User::class,
                $user->id,
                [
                    'section' => $section,
                    'old' => $before,
                    'new' => $this->memberSnapshot($user->fresh()),
                    'password_changed' => ! empty($validated['password']),
                ]
            );
        });

        return response()->json([
            'item' => $this->formatUser($user->fresh(), $section),
            'stats' => $this->stats($request->user()),
        ]);
    }

    public function invitationCode(Request $request, OrganizationInvitations $invitations): JsonResponse
    {
        return response()->json(['code' => $invitations->code($request->user())]);
    }

    public function inviteTrainers(Request $request, OrganizationInvitations $invitations): JsonResponse
    {
        $invitations->organization($request->user());
        $data = $request->validate([
            'emails' => ['required', 'string', 'max:4000'],
            'locale' => ['nullable', 'string', Rule::in(['ru', 'en'])],
        ]);
        $emails = $this->parseEmails($data['emails']);
        $invitations->send($request->user(), $emails, $this->organizationLocale($request->user(), $data['locale'] ?? null));

        return response()->json(['invited' => $emails, 'stats' => $this->stats($request->user())]);
    }

    public function resendPendingInvitation(Request $request, WaitConfirmationInvitation $invitation, OrganizationInvitations $invitations): JsonResponse
    {
        $data = $request->validate(['locale' => ['nullable', 'string', Rule::in(['ru', 'en'])]]);
        $invitations->resend($request->user(), $invitation, $this->organizationLocale($request->user(), $data['locale'] ?? null));

        return response()->json(['resent' => true, 'stats' => $this->stats($request->user())]);
    }

    public function destroyPendingInvitation(Request $request, WaitConfirmationInvitation $invitation): JsonResponse
    {
        abort_unless(! $invitation->confirmed && $this->canAccessInvitation($request->user(), $invitation), 403);

        $before = [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'inviting_id' => $invitation->inviting_id,
            'confirmed' => $invitation->confirmed,
            'created_at' => $invitation->created_at?->toDateTimeString(),
        ];

        DB::transaction(function () use ($request, $invitation, $before): void {
            $invitation = WaitConfirmationInvitation::query()->where('target_role', 'Coach')->lockForUpdate()->findOrFail($invitation->id);
            abort_unless(! $invitation->confirmed && $this->canAccessInvitation($request->user(), $invitation), 403);
            $invitation->delete();

            DB::table('activity_log')->insert([
                'log_name' => 'panel',
                'description' => __('exports.invitation_deleted'),
                'subject_type' => WaitConfirmationInvitation::class,
                'subject_id' => $before['id'],
                'event' => 'trainer.invitation.deleted',
                'causer_type' => User::class,
                'causer_id' => $request->user()->id,
                'properties' => json_encode([
                    'old' => $before,
                    'new' => null,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'deleted' => true,
            'stats' => $this->stats($request->user()),
        ]);
    }

    public function showTrainer(Request $request, User $trainer): JsonResponse
    {
        abort_unless($this->userHasRole($trainer, 'Coach'), 404);
        abort_unless($this->canAccessTrainer($request->user(), $trainer), 403);

        $tournamentId = $request->filled('tournament_id') ? $request->integer('tournament_id') : null;
        $perPage = max(5, min(50, (int) $request->integer('per_page', 10)));
        $students = $this->trainerStudents($trainer, $tournamentId)->paginate($perPage);

        return response()->json([
            'trainer' => $this->formatTrainerDetail($trainer),
            'capabilities' => ['detach_students' => $request->user()->hasProjectRole('Organization')],
            'documents' => collect(['passport' => 'passport', 'brand' => 'brand', 'insurance' => 'insurance', 'iko_card' => 'ikoCard'])
                ->map(fn (string $key, string $column): array => [
                    'key' => $key, 'file' => app(ProtectedMedia::class)->documentUrl($trainer, $column),
                ])->values(),
            'students' => [
                'data' => $students->getCollection()
                    ->map(fn (User $student) => $this->formatUser($student, 'students'))
                    ->values(),
                'meta' => [
                    'current_page' => $students->currentPage(),
                    'last_page' => $students->lastPage(),
                    'per_page' => $students->perPage(),
                    'total' => $students->total(),
                    'from' => $students->firstItem(),
                    'to' => $students->lastItem(),
                ],
            ],
            'filters' => [
                'tournaments' => $this->trainerTournamentOptions($trainer),
            ],
        ]);
    }

    public function exportTrainerStudents(Request $request, User $trainer)
    {
        abort_unless($this->userHasRole($trainer, 'Coach'), 404);
        abort_unless($this->canAccessTrainer($request->user(), $trainer), 403);

        $tournamentId = $request->filled('tournament_id') ? $request->integer('tournament_id') : null;
        $format = $request->string('format')->toString() === 'pdf' ? 'pdf' : 'xlsx';

        if (PanelTasks::shouldDefer($request)) {
            return app(PanelTasks::class)->defer($request, 'trainer', ['trainer' => $trainer->id, 'filters' => ['format' => $format, 'tournament_id' => $tournamentId]]);
        }

        if ($format === 'pdf') {
            $this->writeTeamActivity(
                $request->user(),
                __('exports.trainer_students_exported'),
                'trainer.students.exported',
                User::class,
                $trainer->id,
                [
                    'trainer' => $this->memberSnapshot($trainer),
                    'format' => 'pdf',
                    'filters' => ['tournament_id' => $tournamentId],
                ]
            );

            return app(PdfRenderer::class)->render('pdf.trainer-students', [
                'trainerName' => trim($trainer->last_name.' '.$trainer->first_name),
                'students' => $this->trainerStudents($trainer, $tournamentId)->get(),
            ], 'trainer-students.pdf');
        }

        $this->writeTeamActivity(
            $request->user(),
            __('exports.trainer_students_exported'),
            'trainer.students.exported',
            User::class,
            $trainer->id,
            [
                'trainer' => $this->memberSnapshot($trainer),
                'format' => 'xlsx',
                'filters' => ['tournament_id' => $tournamentId],
            ]
        );

        return Excel::download(new TrainerStudentsExport($trainer, $tournamentId), 'trainer-students.xlsx');
    }

    public function destroyTrainer(Request $request, User $trainer): JsonResponse
    {
        abort_unless($this->userHasRole($trainer, 'Coach'), 404);
        abort_unless($this->canAccessTrainer($request->user(), $trainer), 403);
        abort_unless($this->userHasRole($request->user(), 'Organization') || $this->userHasRole($request->user(), 'Secretary'), 403);

        $studentCount = $this->trainerStudents($trainer)->count();
        $before = [
            'id' => $trainer->id,
            'first_name' => $trainer->first_name,
            'last_name' => $trainer->last_name,
            'email' => $trainer->email,
            'organization_id' => $trainer->organization_id,
            'deleted_at' => $trainer->deleted_at,
            'students_count' => $studentCount,
        ];

        DB::transaction(function () use ($request, $trainer, $before): void {
            $trainer->delete();

            DB::table('activity_log')->insert([
                'log_name' => 'panel',
                'description' => __('exports.trainer_deactivated'),
                'subject_type' => User::class,
                'subject_id' => $trainer->id,
                'event' => 'trainer.deactivated',
                'causer_type' => User::class,
                'causer_id' => $request->user()->id,
                'properties' => json_encode([
                    'trainer' => [
                        'id' => $trainer->id,
                        'full_name' => trim($trainer->last_name.' '.$trainer->first_name),
                    ],
                    'old' => $before,
                    'new' => [
                        'deleted_at' => $trainer->deleted_at?->toDateTimeString(),
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'deleted' => true,
            'stats' => $this->stats($request->user()),
        ]);
    }

    public function detachTrainerStudent(Request $request, User $trainer, User $student): JsonResponse
    {
        abort_unless($this->userHasRole($trainer, 'Coach'), 404);
        abort_unless($this->userHasRole($student, 'Student'), 404);
        abort_unless($this->canAccessTrainer($request->user(), $trainer), 403);
        abort_unless($this->userHasRole($request->user(), 'Organization'), 403);
        abort_unless((int) $student->coach_id === (int) $trainer->id, 404);

        $before = ['coach_id' => $student->coach_id];

        DB::transaction(function () use ($request, $trainer, $student, $before): void {
            $student->forceFill(['coach_id' => null])->save();

            DB::table('activity_log')->insert([
                'log_name' => 'panel',
                'description' => __('exports.student_detached'),
                'subject_type' => User::class,
                'subject_id' => $student->id,
                'event' => 'trainer.student.detached',
                'causer_type' => User::class,
                'causer_id' => $request->user()->id,
                'properties' => json_encode([
                    'trainer' => [
                        'id' => $trainer->id,
                        'full_name' => trim($trainer->last_name.' '.$trainer->first_name),
                    ],
                    'student' => [
                        'id' => $student->id,
                        'full_name' => trim($student->last_name.' '.$student->first_name),
                    ],
                    'old' => $before,
                    'new' => ['coach_id' => null],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'detached' => true,
        ]);
    }

    private function trainerStudents(User $trainer, ?int $tournamentId = null): Builder
    {
        return $this->baseRoleQuery('Student')
            ->where('coach_id', $trainer->id)
            ->with(['coach' => fn ($q) => $q->withTrashed()->select('id', 'first_name', 'last_name', 'club')])
            ->select($this->userColumns())
            ->when($tournamentId, fn (Builder $query) => $query->whereExists(function ($subQuery) use ($tournamentId): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('student_tournaments')
                    ->whereColumn('student_tournaments.student_id', 'users.id')
                    ->where('student_tournaments.tournament_id', $tournamentId);
            }))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function trainerTournamentOptions(User $trainer): array
    {
        return Tournament::query()
            ->select(['tournaments.id', 'tournaments.name', 'tournaments.date'])
            ->whereExists(function ($query) use ($trainer): void {
                $query
                    ->selectRaw('1')
                    ->from('student_tournaments')
                    ->join('users', 'users.id', '=', 'student_tournaments.student_id')
                    ->whereColumn('student_tournaments.tournament_id', 'tournaments.id')
                    ->where('users.coach_id', $trainer->id);
            })
            ->orderByDesc('date')
            ->orderBy('name')
            ->get()
            ->map(fn (Tournament $tournament) => [
                'id' => $tournament->id,
                'name' => trim($tournament->name.($tournament->date ? ' · '.$tournament->date->format('d.m.Y') : '')),
            ])
            ->all();
    }

    private function items(Request $request, string $section, int $perPage): array
    {
        if ($section === 'pending') {
            return $this->pendingItems($request, $perPage);
        }

        $sort = $request->string('sort', 'name')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $query = $this->baseQuery($request->user(), $section)
            ->when($request->filled('search'), fn (Builder $query) => $this->applySearch($query, $request->string('search')->toString(), $section));

        if ($section === 'students' && $sort === 'name') {
            $query->orderBy('created_at')->orderBy('id');
        } else {
            match ($sort) {
                'age' => $query->orderBy('age', $direction),
                'weight' => $query->orderBy('weight', $direction),
                'club' => $section === 'students'
                    ? $query->orderByRaw("COALESCE((select coaches.club from users as coaches where coaches.id = users.coach_id), '') {$direction}")
                    : $query->orderBy('club', $direction),
                default => $query->orderBy('last_name', $direction)->orderBy('first_name', $direction),
            };
        }

        $paginator = $query->paginate($perPage);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (User $user) => $this->formatUser($user, $section))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function pendingItems(Request $request, int $perPage): array
    {
        $query = WaitConfirmationInvitation::query()->where('target_role', 'Coach')
            ->select(['id', 'email', 'created_at'])
            ->where('confirmed', false)
            ->where('organization_id', $this->organizationId($request->user()))
            ->when($request->filled('search'), fn ($query) => $query->where('email', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest();

        $paginator = $query->paginate($perPage);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (WaitConfirmationInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'created_at' => $invitation->created_at?->format('d.m.Y'),
                ])
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function pendingExportRows(Request $request)
    {
        return WaitConfirmationInvitation::query()->where('target_role', 'Coach')
            ->select(['id', 'email', 'created_at'])
            ->where('confirmed', false)
            ->where('organization_id', $this->organizationId($request->user()))
            ->when($request->filled('search'), fn ($query) => $query->where('email', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest()
            ->lazy(500);
    }

    private function stats(User $user): array
    {
        return [
            ...($user->hasProjectRole('Organization') ? [
                'judges' => $this->baseQuery($user, 'judges')->count(),
                'secretaries' => $this->baseQuery($user, 'secretaries')->count(),
            ] : []),
            'trainers' => $this->baseQuery($user, 'trainers')->count(),
            'students' => $this->baseQuery($user, 'students')->count(),
            'pending' => WaitConfirmationInvitation::query()->where('target_role', 'Coach')
                ->where('confirmed', false)
                ->where('organization_id', $this->organizationId($user))
                ->count(),
        ];
    }

    private function baseQuery(User $user, string $section): Builder
    {
        $orgId = $this->organizationId($user);

        if (! $orgId || ! isset(self::ROLE_MAP[$section])) {
            return User::query()->whereRaw('1 = 0');
        }

        if ($section === 'students') {
            $coachIds = $this->baseRoleQuery('Coach')->withTrashed()
                ->where('organization_id', $orgId)
                ->pluck('users.id');

            return $this->baseRoleQuery('Student')
                ->with(['coach' => fn ($query) => $query->withTrashed()->select('id', 'first_name', 'last_name', 'club')])
                ->whereIn('coach_id', $coachIds)
                ->select($this->userColumns());
        }

        return $this->baseRoleQuery(self::ROLE_MAP[$section])
            ->where('organization_id', $orgId)
            ->select($this->userColumns());
    }

    private function baseRoleQuery(string $role): Builder
    {
        $query = User::query();

        return $query->where(function (Builder $query) use ($role): void {
            if (Schema::hasTable('model_has_roles')) {
                $query->whereExists(function ($subQuery) use ($role): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereColumn('model_has_roles.model_id', 'users.id')
                        ->where('model_has_roles.model_type', User::class)
                        ->where('roles.name', $role);
                });
            }

            $roleIds = DB::table('roles')->where('name', $role)->pluck('id');

            if ($roleIds->isNotEmpty()) {
                $query->orWhereIn('role_id', $roleIds);
            }
        });
    }

    private function applySearch(Builder $query, string $search, string $section): void
    {
        $query->where(function (Builder $query) use ($search, $section): void {
            $query
                ->where('last_name', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%");

            if (in_array($section, ['judges', 'secretaries'], true)) {
                $query->orWhere('email', 'like', "%{$search}%");
            }
        });
    }

    private function formatUser(User $user, string $section): array
    {
        $base = [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => trim($user->last_name.' '.$user->first_name),
            'email' => $user->is_external ? null : $user->email,
            'is_external' => (bool) $user->is_external,
        ];

        if ($section === 'judges') {
            return $base + [
                'judge_position' => $user->judge_position,
                'judge_position_label' => $this->judgePositionOptions()[$user->judge_position] ?? '—',
            ];
        }

        if ($section === 'secretaries') {
            return $base;
        }

        return $base + [
            'avatar' => $user->avatar ? asset('storage/'.$user->avatar) : null,
            'age' => $user->age,
            'weight' => $user->weight,
            'rang' => $user->rang,
            'club' => $section === 'students' ? $user->coach?->club : $user->club,
            'coach_name' => $user->coach ? trim($user->coach->last_name.' '.$user->coach->first_name) : null,
            'can_attach_to_tournaments' => (bool) $user->can_attach_to_tournaments_for_students,
            'can_attach_to_examination' => (bool) $user->can_attach_to_examination_for_students,
        ];
    }

    private function formatTrainerDetail(User $trainer): array
    {
        return [
            'id' => $trainer->id,
            'first_name' => $trainer->first_name,
            'last_name' => $trainer->last_name,
            'full_name' => trim($trainer->last_name.' '.$trainer->first_name),
            'avatar' => $trainer->avatar ? asset('storage/'.$trainer->avatar) : null,
            'email' => $trainer->is_external ? null : $trainer->email,
            'is_external' => (bool) $trainer->is_external,
            'gender' => $trainer->gender,
            'age' => $this->ageNumber($trainer->birthday),
            'age_label' => $trainer->age,
            'birthday' => $this->dateLabel($trainer->birthday),
            'weight' => $trainer->weight,
            'rang' => $trainer->rang,
            'club' => $trainer->club,
            'belt' => $this->beltFor((string) $trainer->rang),
        ];
    }

    private function canAccessTrainer(User $viewer, User $trainer): bool
    {
        $orgId = $this->organizationId($viewer);

        return $viewer->hasAnyProjectRole(['Organization', 'Secretary'])
            && $orgId && (int) $trainer->organization_id === (int) $orgId;
    }

    private function organizationId(User $user): ?int
    {
        if ($this->userHasRole($user, 'Organization')) {
            return $user->id;
        }

        if ($this->userHasRole($user, 'Secretary')) {
            return $user->organization_id;
        }

        return $user->organization_id ?: $user->id;
    }

    private function userHasRole(User $user, string $role): bool
    {
        $roleIds = DB::table('roles')->where('name', $role)->pluck('id');

        if ($roleIds->contains($user->role_id)) {
            return true;
        }

        if (! Schema::hasTable('model_has_roles')) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.name', $role)
            ->exists();
    }

    private function ageNumber(?string $birthday): ?int
    {
        return $birthday ? Carbon::parse($birthday)->age : null;
    }

    private function dateLabel(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d.m.Y') : null;
    }

    private function beltFor(string $rank): array
    {
        preg_match('/\d+/', $rank, $matches);
        $number = isset($matches[0]) ? (int) $matches[0] : null;
        $isDan = str_contains(mb_strtolower($rank), 'дан') || str_contains(mb_strtolower($rank), 'dan');

        if ($isDan) {
            return ['label_key' => 'blackBelt', 'color' => '#111827', 'accent' => '#d6a233', 'progress' => 100];
        }

        $map = [
            10 => ['whiteBelt', '#f8fafc', '#d1d5db', 10],
            9 => ['orangeBelt', '#fb923c', '#fde68a', 20],
            8 => ['blueBelt', '#2563eb', '#f8fafc', 30],
            7 => ['blueBelt', '#2563eb', '#facc15', 40],
            6 => ['yellowBelt', '#facc15', '#f8fafc', 50],
            5 => ['yellowBelt', '#facc15', '#22c55e', 60],
            4 => ['greenBelt', '#16a34a', '#f8fafc', 70],
            3 => ['greenBelt', '#16a34a', '#a16207', 80],
            2 => ['brownBelt', '#92400e', '#f8fafc', 90],
            1 => ['brownBelt', '#92400e', '#111827', 96],
        ];

        [$labelKey, $color, $accent, $progress] = $map[$number] ?? ['beltNotSet', '#e5e7eb', '#9ca3af', 0];

        return [
            'label_key' => $labelKey,
            'color' => $color,
            'accent' => $accent,
            'progress' => $progress,
        ];
    }

    private function canAccessInvitation(User $user, WaitConfirmationInvitation $invitation): bool
    {
        return $user->hasAnyProjectRole(['Organization', 'Secretary'])
            && $this->organizationId($user)
            && $invitation->target_role === 'Coach'
            && (int) $invitation->organization_id === $this->organizationId($user);
    }

    private function normalizeSection(string $section): string
    {
        return in_array($section, ['judges', 'secretaries', 'trainers', 'students', 'pending'], true)
            ? $section
            : 'trainers';
    }

    private function normalizeEditableSection(string $section): string
    {
        abort_unless(in_array($section, ['judges', 'secretaries'], true), 404);

        return $section;
    }

    private function validateMember(Request $request, string $section, ?User $user = null): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
        ];

        if ($section === 'judges') {
            $rules['judge_position'] = ['required', 'string', Rule::in(array_keys($this->judgePositionOptions()))];
        }

        return $request->validate($rules);
    }

    private function parseEmails(string $value): array
    {
        $emails = collect(explode(',', $value))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty() || $emails->contains(fn (string $email) => ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages([
                'emails' => __('exports.invalid_emails'),
            ]);
        }

        return $emails->all();
    }

    private function organizationLocale(User $user, ?string $fallback): string
    {
        $organization = $this->userHasRole($user, 'Organization') ? $user : $user->organization;

        foreach ([$organization, $user] as $model) {
            if (! $model) {
                continue;
            }

            foreach (['locale', 'language'] as $column) {
                if (Schema::hasColumn('users', $column) && in_array($model->{$column}, ['ru', 'en'], true)) {
                    return $model->{$column};
                }
            }
        }

        return in_array($fallback, ['ru', 'en'], true) ? $fallback : 'ru';
    }

    private function fillMember(User $user, array $data, string $section, int $orgId): void
    {
        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'];
        $user->name = trim($data['last_name'].' '.$data['first_name']);
        $user->email = $data['email'];
        $user->organization_id = $orgId;

        if (Schema::hasColumn('users', 'judge_position')) {
            $user->judge_position = $section === 'judges' ? $data['judge_position'] : null;
        }
    }

    private function syncRole(User $user, string $role): void
    {
        $roleId = $this->roleId($role);

        if (! $roleId) {
            return;
        }

        if (Schema::hasColumn('users', 'role_id')) {
            $user->forceFill(['role_id' => $roleId])->save();
        }

        if (! Schema::hasTable('model_has_roles')) {
            return;
        }

        $roleLink = [
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
        ];

        if (! DB::table('model_has_roles')->where($roleLink)->exists()) {
            DB::table('model_has_roles')->insert($roleLink);
        }
    }

    private function roleId(string $role): ?int
    {
        $query = DB::table('roles')->where('name', $role);
        $id = $query->value('id');

        if ($id) {
            return (int) $id;
        }

        $payload = ['name' => $role];

        if (Schema::hasColumn('roles', 'guard_name')) {
            $payload['guard_name'] = 'web';
        }

        return (int) DB::table('roles')->insertGetId($payload);
    }

    private function userColumns(): array
    {
        return [
            'is_external',
            'id',
            'name',
            'first_name',
            'last_name',
            'email',
            'avatar',
            'age',
            'birthday',
            'weight',
            'rang',
            'club',
            'coach_id',
            'organization_id',
            'created_at',
            'judge_position',
            'can_attach_to_tournaments_for_students',
            'can_attach_to_examination_for_students',
        ];
    }

    private function judgePositionOptions(): array
    {
        return [
            'referee_score' => __('exports.referee'),
            'judge1_score' => __('exports.judge1'),
            'judge2_score' => __('exports.judge2'),
            'judge3_score' => __('exports.judge3'),
            'judge4_score' => __('exports.judge4'),
        ];
    }

    private function exportHeaders(string $section): array
    {
        return match ($section) {
            'judges' => ['№', __('exports.name'), 'Email', __('exports.score_column')],
            'secretaries' => ['№', __('exports.name'), 'Email'],
            'trainers' => ['№', __('exports.name'), 'Email', __('exports.birthday'), __('exports.age'), __('exports.weight'), __('exports.rank'), __('exports.club')],
            'pending' => ['№', 'Email', __('exports.invited')],
            default => ['№', __('exports.name'), __('exports.birthday'), __('exports.age'), __('exports.weight'), __('exports.rank'), __('exports.coach'), __('exports.club')],
        };
    }

    private function exportRow(User|WaitConfirmationInvitation $user, string $section, int $index): array
    {
        if ($section === 'pending' && $user instanceof WaitConfirmationInvitation) {
            return [$index, $user->email, $user->created_at?->format('d.m.Y H:i') ?? '-'];
        }

        $formatted = $this->formatUser($user, $section);
        $fullName = $formatted['full_name'] !== '' ? $formatted['full_name'] : ($user->name ?? '-');

        return match ($section) {
            'judges' => [$index, $fullName, $formatted['email'] ?: '-', $formatted['judge_position_label']],
            'secretaries' => [$index, $fullName, $formatted['email'] ?: '-'],
            'trainers' => [
                $index,
                $fullName,
                $formatted['email'] ?: '-',
                $this->dateLabel($user->birthday) ?: '-',
                ExportLabels::age($user->birthday) ?? '-',
                $this->weightLabel($formatted['weight']),
                ExportLabels::rank($formatted['rang']) ?: '-',
                $formatted['club'] ?: '-',
            ],
            default => [
                $index,
                $fullName,
                $this->dateLabel($user->birthday) ?: '-',
                ExportLabels::age($user->birthday) ?? '-',
                $this->weightLabel($formatted['weight']),
                ExportLabels::rank($formatted['rang']) ?: '-',
                $formatted['coach_name'] ?: '-',
                $user->coach?->club ?: '-',
            ],
        };
    }

    private function weightLabel(mixed $weight): string
    {
        return $weight ? $weight.' '.__('exports.kg') : '-';
    }

    private function writeTeamExportActivity(User $user, string $section, int $count, array $filters): void
    {
        $this->writeTeamActivity(
            $user,
            __('exports.team_exported'),
            'team.exported',
            User::class,
            $this->organizationId($user),
            [
                'section' => $section,
                'count' => $count,
                'filters' => $filters,
            ]
        );
    }

    private function writeTeamActivity(User $causer, string $description, string $event, ?string $subjectType, int|string|null $subjectId, array $properties): void
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

    private function memberSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => trim($user->last_name.' '.$user->first_name),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'organization_id' => $user->organization_id,
            'coach_id' => $user->coach_id,
            'birthday' => $this->dateLabel($user->birthday),
            'age' => $this->ageNumber($user->birthday),
            'weight' => $user->weight,
            'rang' => $user->rang,
            'club' => $user->club,
            'judge_position' => $user->judge_position,
            'deleted_at' => $user->deleted_at?->toDateTimeString(),
        ];
    }
}
