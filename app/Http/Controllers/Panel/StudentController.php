<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Pool;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\RatingService;
use App\Services\StudentMedalService;
use App\Services\Students\StudentCompetitionHistory;
use App\Services\Students\StudentDocumentStatus;
use App\Services\Students\StudentProfileAccess;
use App\Services\Students\UpdateStudentProfile;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentController extends Controller
{
    public function selfProfile(Request $request, RatingService $rating): JsonResponse
    {
        abort_unless(app(StudentProfileAccess::class)->isSelf($request->user(), $request->user()), 403);

        return $this->show($request, $request->user(), $rating);
    }

    public function updateSelf(Request $request, UpdateStudentProfile $update, RatingService $rating): JsonResponse
    {
        abort_unless(app(StudentProfileAccess::class)->isSelf($request->user(), $request->user()), 403);
        $student = $update->update($request->user(), $request->user(), $request);
        $request->setUserResolver(fn () => $student);

        return $this->selfProfile($request, $rating);
    }

    public function show(Request $request, User $student, RatingService $rating): JsonResponse
    {
        abort_unless($this->userHasRole($student, 'Student'), 404);
        abort_unless($this->canViewStudent($request->user(), $student), 403);

        $student->load(['coach' => fn ($query) => $query->withTrashed()->select('id', 'first_name', 'last_name', 'club', 'organization_id')]);

        $medals = app(StudentMedalService::class)->forStudent($student);
        $self = app(StudentProfileAccess::class)->isSelf($request->user(), $student);

        return response()->json([
            'student' => $this->formatStudent($student),
            'can_confirm_documents' => $this->canUpdateStudentDocuments($request->user(), $student),
            ...($self ? [
                'profile' => $student->only(['first_name', 'last_name', 'patronymic', 'email', 'gender', 'birthday', 'weight', 'height', 'rang', 'city_training', 'number_brand', 'number_iko', 'number_certificate', 'last_examination_date', 'last_examination_city', 'last_receiving']),
                'capabilities' => app(StudentProfileAccess::class)->capabilities($student, $student),
                'document_status' => app(StudentDocumentStatus::class)->evaluate($student),
            ] : []),
            'rating' => [
                'kumite' => $this->formatRatingCard($rating->resolveUserTopPosition($student, 'kumite'), $medals['kumite']),
                'kata' => $this->formatRatingCard($rating->resolveUserTopPosition($student, 'kata'), $medals['kata']),
                'record' => $this->competitiveRecord($student),
            ],
            'documents' => $this->documents($student),
            ...$this->history($request->user(), $student),
        ]);
    }

    public function historyPage(Request $request, User $student): JsonResponse
    {
        abort_unless($this->userHasRole($student, 'Student'), 404);
        abort_unless($this->canViewStudent($request->user(), $student), 403);
        $data = $request->validate(['kind' => ['required', 'in:tournaments,wins,losses'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);

        return response()->json(app(StudentCompetitionHistory::class)->page($student, $request->user(), $data['kind'], $data['page'] ?? 1, $data['per_page'] ?? 20));
    }

    public function updateDocument(Request $request, User $student, string $document): JsonResponse
    {
        abort_unless($this->userHasRole($student, 'Student'), 404);
        abort_unless($this->canUpdateStudentDocuments($request->user(), $student), 403);

        $config = $this->documentUpdateConfig($document);
        abort_unless($config, 404);

        $validated = $request->validate($config['rules']);
        $before = $student->only($config['columns']);

        DB::transaction(function () use ($student, $validated, $config, $request, $document, $before): void {
            $student->forceFill($validated)->save();

            DB::table('activity_log')->insert([
                'log_name' => 'panel',
                'description' => 'Обновлены документы ученика',
                'subject_type' => User::class,
                'subject_id' => $student->id,
                'event' => 'student.document.updated',
                'causer_type' => User::class,
                'causer_id' => $request->user()->id,
                'properties' => json_encode([
                    'document' => $document,
                    'student' => [
                        'id' => $student->id,
                        'full_name' => trim($student->last_name.' '.$student->first_name),
                    ],
                    'old' => $before,
                    'new' => $student->only($config['columns']),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'documents' => $this->documents($student->refresh()),
        ]);
    }

    private function canUpdateStudentDocuments(User $viewer, User $student): bool
    {
        return $this->userHasAnyRole($viewer, ['Organization', 'Secretary'])
            && $this->canViewStudent($viewer, $student);
    }

    private function documentUpdateConfig(string $document): ?array
    {
        return match ($document) {
            'insurance' => [
                'columns' => ['is_success_insurance', 'insurance_close_date'],
                'rules' => [
                    'is_success_insurance' => ['required', 'boolean'],
                    'insurance_close_date' => ['nullable', 'date'],
                ],
            ],
            'ikoCard' => [
                'columns' => ['is_success_iko_card', 'is_iko_card_included_check'],
                'rules' => [
                    'is_success_iko_card' => ['required', 'boolean'],
                    'is_iko_card_included_check' => ['required', 'boolean'],
                ],
            ],
            'certificate' => [
                'columns' => ['is_success_certificate', 'is_certificate_included_check'],
                'rules' => [
                    'is_success_certificate' => ['required', 'boolean'],
                    'is_certificate_included_check' => ['required', 'boolean'],
                ],
            ],
            'passport' => [
                'columns' => ['is_success_passport'],
                'rules' => [
                    'is_success_passport' => ['required', 'boolean'],
                ],
            ],
            'brand' => [
                'columns' => ['is_success_brand'],
                'rules' => [
                    'is_success_brand' => ['required', 'boolean'],
                ],
            ],
            default => null,
        };
    }

    private function canViewStudent(User $viewer, User $student): bool
    {
        if ($viewer->hasProjectRole('Student')) {
            return app(StudentProfileAccess::class)->isSelf($viewer, $student);
        }
        if ($viewer->hasProjectRole('Coach')) {
            return app(StudentProfileAccess::class)->owns($viewer, $student);
        }
        if (! $viewer->hasAnyProjectRole(['Organization', 'Secretary'])) {
            return false;
        }
        $organizationId = $this->organizationId($viewer);

        if (! $organizationId) {
            return false;
        }

        return $student->coach_id
            ? User::withTrashed()
                ->role('Coach')
                ->whereKey($student->coach_id)
                ->where('organization_id', $organizationId)
                ->exists()
            : false;
    }

    private function formatStudent(User $student): array
    {
        $belt = $this->beltFor((string) $student->rang);

        return [
            'id' => $student->id,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'full_name' => trim($student->last_name.' '.$student->first_name),
            'avatar' => $student->avatar ? asset('storage/'.$student->avatar) : null,
            'club' => $student->coach?->club,
            'coach_name' => $student->coach ? trim($student->coach->last_name.' '.$student->coach->first_name) : null,
            'age' => $this->ageNumber($student->birthday),
            'age_label' => $student->age,
            'birthday' => $this->dateLabel($student->birthday),
            'gender' => $student->gender,
            'weight' => $student->weight,
            'height' => Schema::hasColumn('users', 'height') ? $student->height : null,
            'rang' => $student->rang,
            'belt' => $belt,
        ];
    }

    private function formatRatingCard(?array $rating, array $medals): array
    {
        return [
            'label' => $rating['label'] ?? '—',
            'points' => (int) ($rating['rating_points'] ?? 0),
            'year' => (string) now()->year,
            'group' => $rating['group'] ?? null,
            'subtitle' => $rating['subtitle'] ?? null,
            'medals' => $medals,
        ];
    }

    private function competitiveRecord(User $student): array
    {
        $pools = $this->competitivePoolQuery($student)
            ->get(['pools.id', 'pools.student_id', 'pools.opponent_id', 'pools.winner_id']);

        $wins = $pools->where('winner_id', $student->id)->count();
        $losses = $pools->filter(fn (Pool $pool) => (int) $pool->winner_id !== (int) $student->id)->count();

        return [
            'wins' => $wins,
            'losses' => $losses,
            'total' => $wins + $losses,
        ];
    }

    private function documents(User $student): array
    {
        $documents = [
            $this->documentItem($student, 'insurance', 'insurance', 'is_success_insurance', [
                'action_type' => 'insurance',
                'form' => [
                    'is_success_insurance' => (bool) $student->is_success_insurance,
                    'insurance_close_date' => $this->dateInputValue($student->insurance_close_date),
                ],
            ]),
            $this->documentItem($student, 'ikoCard', 'iko_card', 'is_success_iko_card', [
                'action_type' => 'check',
                'check_field' => 'is_iko_card_included_check',
                'form' => [
                    'is_success_iko_card' => (bool) $student->is_success_iko_card,
                    'is_iko_card_included_check' => (bool) $student->is_iko_card_included_check,
                ],
            ]),
            $this->documentItem($student, 'certificate', 'certificate', 'is_success_certificate', [
                'action_type' => 'check',
                'check_field' => 'is_certificate_included_check',
                'form' => [
                    'is_success_certificate' => (bool) $student->is_success_certificate,
                    'is_certificate_included_check' => (bool) $student->is_certificate_included_check,
                ],
            ]),
            $this->documentItem($student, 'passport', 'passport', 'is_success_passport', [
                'action_type' => 'toggle',
                'form' => [
                    'is_success_passport' => (bool) $student->is_success_passport,
                ],
            ]),
            $this->documentItem($student, 'brand', 'brand', 'is_success_brand', [
                'action_type' => 'toggle',
                'form' => [
                    'is_success_brand' => (bool) $student->is_success_brand,
                ],
            ]),
        ];

        return [
            'documents' => $documents,
            'rows' => [
                [
                    $this->documentField('brandNumber', $student->number_brand),
                    $this->documentField('ikoNumber', $student->number_iko),
                    $this->documentField('certificateNumber', $student->number_certificate),
                    $this->documentField('lastExamDate', $this->dateLabel($student->last_examination_date)),
                    $this->documentField('lastExamCity', $student->last_examination_city),
                ],
                [
                    $this->documentField('lastReceiving', $student->last_receiving),
                    null,
                    null,
                    null,
                    null,
                ],
                [
                    ['document' => 'insurance'],
                    ['document' => 'ikoCard'],
                    ['document' => 'certificate'],
                    ['document' => 'passport'],
                    ['document' => 'brand'],
                ],
                [
                    $this->documentField('insuranceCloseDate', $this->dateLabel($student->insurance_close_date)),
                    $this->documentField('includedInDocumentCheck', $this->yesNo($student->is_iko_card_included_check)),
                    $this->documentField('includedInDocumentCheck', $this->yesNo($student->is_certificate_included_check)),
                    null,
                    null,
                ],
            ],
        ];
    }

    private function documentItem(User $student, string $labelKey, string $fileColumn, string $statusColumn, array $extra = []): array
    {
        return [
            'key' => $labelKey,
            'file' => app(ProtectedMedia::class)->documentUrl($student, $fileColumn),
            'confirmed' => (bool) $student->{$statusColumn},
        ] + $extra;
    }

    private function documentField(string $labelKey, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return [
            'label' => $labelKey,
            'value' => $value,
        ];
    }

    private function dateInputValue(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    private function yesNo(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function history(User $viewer, User $student): array
    {
        $service = app(StudentCompetitionHistory::class);

        return [
            'tournaments' => $service->page($student, $viewer, 'tournaments')['data'],
            'fight_records' => [
                'wins' => $service->page($student, $viewer, 'wins', 1, 20)['data'],
                'losses' => $service->page($student, $viewer, 'losses', 1, 20)['data'],
            ],
        ];
    }

    private function competitivePoolQuery(User $student): Builder
    {
        return app(StudentCompetitionHistory::class)->fights($student);
    }

    private function organizationId(User $user): ?int
    {
        if ($this->userHasRole($user, 'Organization')) {
            return $user->id;
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

    private function userHasAnyRole(User $user, array $roles): bool
    {
        return collect($roles)->contains(fn (string $role): bool => $this->userHasRole($user, $role));
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

    private function ageNumber(?string $birthday): ?int
    {
        return $birthday ? Carbon::parse($birthday)->age : null;
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d.m.Y') : null;
    }
}
