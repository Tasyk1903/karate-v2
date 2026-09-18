<?php

namespace App\Http\Controllers\Mobile;

use App\Exports\ExaminationStudentsExport;
use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\User;
use App\Services\Examinations\CoachExaminationEnrollment;
use App\Services\Examinations\StudentExaminationEnrollment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MobileExaminationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:100']]);
        $perPage = max(5, min(30, (int) $request->integer('per_page', 10)));

        $query = $this->baseExamQuery($request->user())
            ->withCount([
                'students as organization_students_count' => fn (Builder $query) => $query->where('users.organization_id', $request->user()->organization_id),
                'students as self_registration_count' => fn (Builder $query) => $query->where('users.id', $request->user()->id),
            ])
            ->when($request->filled('search'), fn (Builder $query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('city'), fn (Builder $query) => $query->where('city', $request->string('city')->toString()))
            ->when($request->filled('receiving'), fn (Builder $query) => $query->where('receiving', 'like', '%'.$request->string('receiving')->toString().'%'))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('date', '<=', $request->date('date_to')))
            ->orderByDesc('date');

        $exams = $query->paginate($perPage);

        return response()->json([
            'data' => $exams->getCollection()->map(fn (Examination $exam): array => $this->formatExam($exam))->values(),
            'meta' => $this->meta($exams),
            'filters' => [
                'cities' => $this->baseExamQuery($request->user())
                    ->select('city')
                    ->whereNotNull('city')
                    ->distinct()
                    ->orderBy('city')
                    ->pluck('city'),
                'receiving' => $this->baseExamQuery($request->user())
                    ->select('receiving')
                    ->whereNotNull('receiving')
                    ->distinct()
                    ->orderBy('receiving')
                    ->pluck('receiving'),
            ],
        ]);
    }

    public function show(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);

        return response()->json([
            'item' => $this->formatExam($examination->loadCount([
                'students as organization_students_count' => fn (Builder $query) => $query->where('users.organization_id', $request->user()->organization_id),
                'students as self_registration_count' => fn (Builder $query) => $query->where('users.id', $request->user()->id),
            ])),
        ]);
    }

    public function students(Request $request, Examination $examination): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);
        $perPage = max(5, min(30, (int) $request->integer('per_page', 10)));
        $coachId = $this->organizationCoachId($request);

        $query = $examination->students()
            ->where('users.organization_id', $request->user()->organization_id)
            ->with('coach:id,club')
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.birthday', 'users.weight', 'users.rang', 'users.coach_id')
            ->when($coachId, fn (Builder $query): Builder => $query->where('users.coach_id', $coachId))
            ->when($request->filled('search'), fn ($q) => $this->searchStudents($q, $request->string('search')->toString()))
            ->orderBy('users.last_name')
            ->orderBy('users.first_name');

        $students = $query->paginate($perPage);

        return response()->json([
            'data' => $students->getCollection()->map(fn (User $student): array => $this->formatStudent($student))->values(),
            'meta' => $this->meta($students),
            'filters' => [
                'coaches' => User::query()
                    ->role('Coach')
                    ->where('organization_id', $request->user()->organization_id)
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->get(['id', 'first_name', 'last_name'])
                    ->map(fn (User $coach): array => [
                        'id' => $coach->id,
                        'full_name' => trim($coach->last_name.' '.$coach->first_name),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function attachOptions(Request $request, Examination $examination): JsonResponse
    {
        abort_unless($request->user()->hasProjectRole('Coach'), 403);
        $this->authorizeExam($request->user(), $examination);
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $students = User::query()->role('Student')
            ->where('coach_id', $request->user()->id)
            ->where('organization_id', $request->user()->organization_id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('examination_student')
                ->whereColumn('examination_student.student_id', 'users.id')->where('examination_id', $examination->id))
            ->when($request->filled('search'), fn ($q) => $this->searchStudents($q, $request->string('search')->toString()))
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.birthday', 'users.weight', 'users.rang', 'users.coach_id')
            ->with('coach:id,club')->orderBy('users.last_name')->orderBy('users.first_name')->orderBy('users.id')
            ->paginate(max(5, min(50, $request->integer('per_page', 20))));

        return response()->json(['data' => $students->map(fn ($s) => $this->formatStudent($s))->values(), 'meta' => $this->meta($students)]);
    }

    public function attachStudents(Request $request, Examination $examination, CoachExaminationEnrollment $enrollment): JsonResponse
    {
        abort_unless($request->user()->hasProjectRole('Coach'), 403);
        $this->authorizeExam($request->user(), $examination);
        $data = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:500'], 'student_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);

        return response()->json(['attached' => $enrollment->attach($request->user(), $examination, array_map('intval', $data['student_ids']))]);
    }

    public function detachStudent(Request $request, Examination $examination, User $student, CoachExaminationEnrollment $enrollment): JsonResponse
    {
        $this->authorizeExam($request->user(), $examination);
        if ($request->user()->projectRoleNames() === ['Student']) {
            abort_unless($request->user()->id === $student->id, 403);

            return response()->json(['detached' => app(StudentExaminationEnrollment::class)->update($student, $examination, false)]);
        }

        return response()->json(['detached' => $enrollment->detach($request->user(), $examination, $student->id)]);
    }

    private function searchStudents(Builder|Relation $query, string $search): void
    {
        foreach (preg_split('/\\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $query->where(fn ($q) => $q->where('users.first_name', 'like', "%{$part}%")->orWhere('users.last_name', 'like', "%{$part}%"));
        }
    }

    public function attachSelf(Request $request, Examination $examination, StudentExaminationEnrollment $enrollment): JsonResponse
    {
        $enrollment->update($request->user(), $examination, true);

        return response()->json(['attached' => [$request->user()->id]]);
    }

    public function exportStudents(Request $request, Examination $examination): BinaryFileResponse
    {
        abort_unless($request->user()->hasProjectRole('Coach'), 403);
        $this->authorizeExam($request->user(), $examination);
        $coachId = $this->organizationCoachId($request);

        $this->writeActivityLog(
            $request,
            'mobile.examination.students.exported',
            'Тренер выгрузил список учеников экзамена',
            $examination,
            null,
            ['coach_id' => $coachId]
        );

        return Excel::download(new ExaminationStudentsExport($examination, $coachId, (int) $request->user()->organization_id), 'examination-students.xlsx');
    }

    private function baseExamQuery(User $user): Builder
    {
        if (! $user->organization_id || ($user->projectRoleNames() === ['Student'] && ! app(StudentExaminationEnrollment::class)->allowed($user))) {
            return Examination::query()->whereRaw('1 = 0');
        }

        return Examination::query()->where('organization_id', $user->organization_id);
    }

    private function authorizeExam(User $user, Examination $exam): void
    {
        if ($user->projectRoleNames() === ['Student']) {
            app(StudentExaminationEnrollment::class)->authorize($user, $exam);

            return;
        }
        app(CoachExaminationEnrollment::class)->authorize($user, $exam);
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
            'receiving' => $exam->receiving,
            'students_count' => (int) ($exam->organization_students_count ?? 0),
            'status' => $completed ? 'completed' : 'planned',
            'can_attach_self' => request()->user()?->projectRoleNames() === ['Student']
                && (int) $exam->self_registration_count === 0,
        ];
    }

    private function formatStudent(User $student): array
    {
        return [
            'id' => $student->id,
            'full_name' => trim($student->last_name.' '.$student->first_name),
            'name' => trim($student->last_name.' '.$student->first_name),
            'age_years' => $student->birthday ? (int) Carbon::parse($student->birthday)->age : null,
            'club' => $student->coach?->club ?? '',
            'age' => $student->age,
            'rang' => $student->rang,
            'weight' => $student->weight,
            'can_detach' => request()->user()?->projectRoleNames() === ['Student']
                ? $student->id === request()->user()->id
                : (int) $student->coach_id === (int) request()->user()?->id,
        ];
    }

    private function organizationCoachId(Request $request): ?int
    {
        if (! $request->filled('coach_id')) {
            return null;
        }

        $coachId = (int) $request->integer('coach_id');
        $exists = User::query()
            ->role('Coach')
            ->where('organization_id', $request->user()->organization_id)
            ->whereKey($coachId)
            ->exists();

        abort_unless($exists, 403);

        return $coachId;
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
            'log_name' => 'mobile',
            'description' => $description,
            'subject_type' => Examination::class,
            'subject_id' => $examination->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $request->user()->id,
            'properties' => json_encode([
                'organization_id' => $request->user()->organization_id,
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
