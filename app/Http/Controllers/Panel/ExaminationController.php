<?php

namespace App\Http\Controllers\Panel;

use App\Exports\ExaminationStudentsExport;
use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExaminationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(5, min(50, (int) $request->integer('per_page', 10)));
        $query = $this->baseExamQuery($request->user())
            ->withCount('students')
            ->when($request->filled('search'), fn (Builder $query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('city'), fn (Builder $query) => $query->where('city', $request->string('city')->toString()))
            ->when($request->filled('coach_id'), fn (Builder $query) => $query->whereHas('students', fn (Builder $students) => $students->where('users.coach_id', $request->integer('coach_id'))))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('date', '<=', $request->date('date_to')))
            ->orderByDesc('date');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'items' => [
                'data' => $paginator->getCollection()->map(fn (Examination $exam) => $this->formatExam($exam))->values(),
                'meta' => $this->meta($paginator),
            ],
            'stats' => $this->stats($request->user()),
            'filters' => [
                'cities' => $this->baseExamQuery($request->user())->select('city')->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
                'coaches' => $this->coaches($request->user()),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOrganization($request->user());
        $data = $this->validateExam($request);
        $data['organization_id'] = $this->organizationId($request->user());

        $exam = DB::transaction(function () use ($request, $data): Examination {
            $exam = Examination::query()->create($data);

            $this->writeActivityLog(
                $request,
                'examination.created',
                'Создан экзамен',
                $exam,
                null,
                $this->examState($exam)
            );

            return $exam;
        });

        return response()->json(['item' => $this->formatExam($exam->loadCount('students'))], 201);
    }

    public function show(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);

        return response()->json([
            'item' => $this->formatExam($examination->loadCount('students')) + [
                'can_export' => $request->user()->hasAnyProjectRole(['Organization', 'Coach']),
                'can_attach_self' => $request->user()->hasProjectRole('Student')
                    && ! $examination->students()->where('users.id', $request->user()->id)->exists(),
            ],
            'coaches' => $this->coaches($request->user()),
        ]);
    }

    public function update(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeOrganizationExam($request->user(), $examination);

        DB::transaction(function () use ($request, $examination): void {
            $before = $this->examState($examination);

            $examination->update($this->validateExam($request));

            $this->writeActivityLog(
                $request,
                'examination.updated',
                'Обновлен экзамен',
                $examination,
                $before,
                $this->examState($examination->refresh())
            );
        });

        return response()->json(['item' => $this->formatExam($examination->loadCount('students'))]);
    }

    public function destroy(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeOrganizationExam($request->user(), $examination);

        DB::transaction(function () use ($request, $examination): void {
            $before = $this->examState($examination);

            $examination->delete();

            $this->writeActivityLog(
                $request,
                'examination.deleted',
                'Удален экзамен',
                $examination,
                $before,
                null
            );
        });

        return response()->json(['deleted' => true]);
    }

    public function students(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);
        $perPage = max(5, min(50, (int) $request->integer('per_page', 10)));
        $query = $examination->students()
            ->with('coach:id,first_name,last_name,club')
            ->when($this->userHasRole($request->user(), 'Coach'), fn (Builder $query) => $query->where('users.coach_id', $request->user()->id))
            ->when($request->filled('coach_id'), fn (Builder $query) => $query->where('users.coach_id', $request->integer('coach_id')))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn (Builder $query) => $query
                    ->where('users.last_name', 'like', "%{$search}%")
                    ->orWhere('users.first_name', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%"));
            })
            ->orderBy('users.last_name')
            ->orderBy('users.first_name');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'items' => [
                'data' => $paginator->getCollection()->map(fn (User $student) => $this->formatStudent($request->user(), $student))->values(),
                'meta' => $this->meta($paginator),
            ],
        ]);
    }

    public function attachOptions(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);

        $attached = $examination->students()->pluck('users.id');
        $students = $this->availableStudentsQuery($request->user())
            ->whereNotIn('users.id', $attached)
            ->with('coach:id,first_name,last_name,club')
            ->select(['id', 'first_name', 'last_name', 'age', 'weight', 'rang', 'coach_id'])
            ->orderBy('last_name')
            ->limit(200)
            ->get()
            ->map(fn (User $student) => $this->formatStudent($request->user(), $student));

        return response()->json(['items' => $students]);
    }

    public function attachStudents(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);
        $data = $request->validate(['student_ids' => ['required', 'array'], 'student_ids.*' => ['integer']]);

        $studentIds = $this->availableStudentsQuery($request->user())
            ->whereIn('users.id', $data['student_ids'])
            ->pluck('users.id')
            ->all();

        $attached = DB::transaction(function () use ($request, $examination, $studentIds): array {
            $result = $examination->students()->syncWithoutDetaching($studentIds);
            $attachedIds = array_map('intval', $result['attached'] ?? []);

            if ($attachedIds !== []) {
                $this->writeActivityLog(
                    $request,
                    'examination.students.attached',
                    'Ученики прикреплены к экзамену',
                    $examination,
                    null,
                    ['students' => $this->usersSummary($attachedIds)]
                );
            }

            return $attachedIds;
        });

        return response()->json(['attached' => $attached]);
    }

    public function attachSelf(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);
        abort_unless($this->userHasRole($request->user(), 'Student'), 403);
        $attached = DB::transaction(function () use ($request, $examination): array {
            $examination = Examination::lockForUpdate()->findOrFail($examination->id);
            $actor = User::lockForUpdate()->findOrFail($request->user()->id);
            User::whereKey($actor->coach_id)->lockForUpdate()->first();
            $this->authorizeExam($actor, $examination);
            $result = $examination->students()->syncWithoutDetaching([$request->user()->id]);
            $attachedIds = array_map('intval', $result['attached'] ?? []);

            if ($attachedIds !== []) {
                $this->writeActivityLog(
                    $request,
                    'examination.student.self_attached',
                    'Ученик записался на экзамен',
                    $examination,
                    null,
                    ['students' => $this->usersSummary($attachedIds)]
                );
            }

            return $attachedIds;
        });

        return response()->json(['attached' => $attached]);
    }

    public function detachStudent(Request $request, Examination $examination, User $student): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);
        abort_unless($this->canDetach($request->user(), $student), 403);
        $detached = DB::transaction(function () use ($request, $examination, $student): bool {
            $examination = Examination::lockForUpdate()->findOrFail($examination->id);
            $student = User::lockForUpdate()->findOrFail($student->id);
            $actor = User::lockForUpdate()->findOrFail($request->user()->id);
            User::whereKey($student->coach_id)->lockForUpdate()->first();
            $this->authorizeExam($actor, $examination);
            abort_unless($this->canDetach($actor, $student), 403);
            $removed = $examination->students()->detach($student->id) > 0;

            if ($removed) {
                $this->writeActivityLog(
                    $request,
                    'examination.student.detached',
                    'Ученик откреплен от экзамена',
                    $examination,
                    ['students' => $this->usersSummary([$student->id])],
                    null
                );
            }

            return $removed;
        });

        return response()->json(['detached' => $detached]);
    }

    public function exportStudents(Request $request, Examination $examination): BinaryFileResponse
    {
        $this->authorizeExam($request->user(), $examination);
        abort_unless($request->user()->hasAnyProjectRole(['Organization', 'Coach']), 403);
        $coachId = $request->integer('coach_id') ?: null;

        if ($this->userHasRole($request->user(), 'Coach')) {
            $coachId = $request->user()->id;
        }

        $this->writeActivityLog(
            $request,
            'examination.students.exported',
            'Выгружен список учеников экзамена',
            $examination,
            null,
            ['coach_id' => $coachId]
        );

        return Excel::download(new ExaminationStudentsExport($examination, $coachId), 'students.xlsx');
    }

    private function validateExam(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'receiving' => ['required', 'string', 'max:255'],
        ]);
    }

    private function baseExamQuery(User $user): Builder
    {
        $query = Examination::query();
        $orgId = $this->organizationId($user);

        if (! $orgId) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->userHasRole($user, 'Student') && ! User::query()->role('Coach')->whereKey($user->coach_id)->where('can_attach_to_examination_for_students', true)->exists()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('organization_id', $orgId);
    }

    private function stats(User $user): array
    {
        $base = $this->baseExamQuery($user);

        return [
            'total' => (clone $base)->count(),
            'planned' => (clone $base)->whereDate('date', '>=', now()->toDateString())->count(),
            'completed' => (clone $base)->whereDate('date', '<', now()->toDateString())->count(),
            'students' => DB::table('examination_student')
                ->join('examinations', 'examinations.id', '=', 'examination_student.examination_id')
                ->where('examinations.organization_id', $this->organizationId($user))
                ->distinct('examination_student.student_id')
                ->count('examination_student.student_id'),
        ];
    }

    private function coaches(User $user): array
    {
        $orgId = $this->organizationId($user);

        if (! $orgId) {
            return [];
        }

        return $this->baseRoleQuery('Coach')
            ->where('organization_id', $orgId)
            ->select(['id', 'first_name', 'last_name'])
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $coach) => ['id' => $coach->id, 'name' => trim($coach->last_name.' '.$coach->first_name)])
            ->values()
            ->all();
    }

    private function formatExam(Examination $exam): array
    {
        $completed = $exam->date?->isPast() && ! $exam->date?->isToday();

        return [
            'id' => $exam->id,
            'name' => $exam->name,
            'city' => $exam->city,
            'date' => $exam->date?->format('Y-m-d'),
            'date_label' => $exam->date?->format('d.m.Y'),
            'weekday' => $exam->date ? ucfirst($exam->date->translatedFormat('l')) : null,
            'receiving' => $exam->receiving,
            'students_count' => $exam->students_count ?? $exam->students()->count(),
            'status' => $completed ? 'completed' : 'planned',
        ];
    }

    private function formatStudent(User $actor, User $student): array
    {
        return [
            'id' => $student->id,
            'full_name' => trim($student->last_name.' '.$student->first_name),
            'age' => $student->age,
            'rang' => $student->rang,
            'coach_name' => $student->coach ? trim($student->coach->last_name.' '.$student->coach->first_name) : null,
            'club' => $student->coach?->club,
            'status' => 'confirmed',
            'can_detach' => $this->canDetach($actor, $student),
        ];
    }

    private function canDetach(User $actor, User $student): bool
    {
        return $this->userHasRole($actor, 'Organization')
            || ($this->userHasRole($actor, 'Coach') && (int) $student->coach_id === (int) $actor->id)
            || ($this->userHasRole($actor, 'Student') && (int) $student->id === (int) $actor->id);
    }

    private function authorizeExam(User $user, Examination $exam): void
    {
        abort_unless($this->baseExamQuery($user)->whereKey($exam->id)->exists(), 403);
    }

    private function authorizeOrganization(User $user): void
    {
        abort_unless($this->userHasRole($user, 'Organization') && $this->organizationId($user), 403);
    }

    private function authorizeOrganizationExam(User $user, Examination $exam): void
    {
        $this->authorizeOrganization($user);
        abort_unless((int) $exam->organization_id === (int) $this->organizationId($user), 403);
    }

    private function organizationId(User $user): ?int
    {
        if ($this->userHasRole($user, 'Organization')) {
            return $user->id;
        }

        return $user->organization_id;
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

    private function userHasAnyRole(User $user, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->userHasRole($user, $role)) {
                return true;
            }
        }

        return false;
    }

    private function baseRoleQuery(string $role): Builder
    {
        return User::query()->where(function (Builder $query) use ($role): void {
            if (Schema::hasTable('model_has_roles')) {
                $query->whereExists(function ($subQuery) use ($role): void {
                    $subQuery->selectRaw('1')
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

    private function availableStudentsQuery(User $user): Builder
    {
        $query = $this->baseRoleQuery('Student');

        if ($this->userHasRole($user, 'Coach')) {
            return $query->where('coach_id', $user->id);
        }

        abort_unless($this->userHasRole($user, 'Organization'), 403);

        $coachIds = $this->baseRoleQuery('Coach')
            ->where('organization_id', $this->organizationId($user))
            ->pluck('users.id');

        return $query->whereIn('coach_id', $coachIds);
    }

    private function writeActivityLog(
        Request $request,
        string $event,
        string $description,
        Examination $examination,
        ?array $old,
        ?array $new
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'panel',
            'description' => $description,
            'subject_type' => Examination::class,
            'subject_id' => $examination->id,
            'event' => $event,
            'causer_type' => get_class($request->user()),
            'causer_id' => $request->user()->id,
            'properties' => json_encode([
                'organization_id' => $this->organizationId($request->user()),
                'examination' => [
                    'id' => $examination->id,
                    'name' => $examination->name,
                ],
                'old' => $old,
                'new' => $new,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function examState(Examination $examination): array
    {
        return [
            'id' => $examination->id,
            'organization_id' => $examination->organization_id,
            'name' => $examination->name,
            'city' => $examination->city,
            'date' => $examination->date?->format('Y-m-d'),
            'receiving' => $examination->receiving,
        ];
    }

    private function usersSummary(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'full_name' => trim($user->last_name.' '.$user->first_name),
            ])
            ->values()
            ->all();
    }

    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
