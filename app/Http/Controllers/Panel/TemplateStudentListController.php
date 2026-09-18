<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ListTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Services\Tournaments\ListCompatibility;
use App\Services\Tournaments\TemplateListInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TemplateStudentListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ownerId = $this->ownerId($request);
        $showAll = $request->boolean('all');
        $perPage = $showAll
            ? max(1, TemplateStudentList::query()->where('user_id', $ownerId)->count())
            : min(max((int) $request->integer('per_page', 6), 1), 50);

        $query = TemplateStudentList::query()
            ->where('user_id', $ownerId)
            ->select([
                'id',
                'name',
                'age_from',
                'age_to',
                'weight_from',
                'weight_to',
                'rang_from',
                'rang_to',
                'gender',
                'list_type',
                'kata_type',
                'sort_order',
                'created_at',
            ]);

        $this->applyFilters($query, $request);

        $lists = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $statsQuery = TemplateStudentList::query()->where('user_id', $ownerId);

        return response()->json([
            'items' => collect($lists->items())->map(fn (TemplateStudentList $list) => $this->payload($list)),
            'meta' => [
                'current_page' => $lists->currentPage(),
                'last_page' => $lists->lastPage(),
                'per_page' => $lists->perPage(),
                'total' => $lists->total(),
                'from' => $lists->firstItem(),
                'to' => $lists->lastItem(),
            ],
            'stats' => [
                'total' => (clone $statsQuery)->count(),
                'active_filters' => $this->activeFiltersCount($request),
                'kata' => (clone $statsQuery)->where('list_type', TemplateStudentList::KATA)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        $data['user_id'] = $this->ownerId($request);
        $data['sort_order'] = ((int) TemplateStudentList::query()
            ->where('user_id', $data['user_id'])
            ->max('sort_order')) + 1;

        $list = DB::transaction(function () use ($data, $request): TemplateStudentList {
            $list = TemplateStudentList::create($data);

            $this->writeActivityLog(
                $request,
                'template_student_list.created',
                'Создан шаблон списка',
                $list,
                null,
                $this->listState($list)
            );

            return $list;
        });

        return response()->json(['item' => $this->payload($list)], 201);
    }

    public function update(Request $request, TemplateStudentList $templateStudentList): JsonResponse
    {
        abort_unless((int) $templateStudentList->user_id === $this->ownerId($request), 403);

        DB::transaction(function () use ($request, $templateStudentList): void {
            $before = $this->listState($templateStudentList);

            $data = $this->validatedData($request);
            $candidate = new TemplateStudentList($data);
            if (($candidate->list_type !== $templateStudentList->list_type || $candidate->kata_type !== $templateStudentList->kata_type)
                && TournamentStudentList::whereHas('listTournament', fn ($q) => $q->where('template_student_list_id', $templateStudentList->id))->exists()) {
                throw ValidationException::withMessages(['list' => __('lists.incompatible')]);
            }
            foreach (Tournament::whereIn('id', ListTournament::where('template_student_list_id', $templateStudentList->id)->select('tournament_id'))->get() as $tournament) {
                app(ListCompatibility::class)->assert($candidate, $tournament);
            }
            $templateStudentList->update($data);

            $this->writeActivityLog(
                $request,
                'template_student_list.updated',
                'Обновлен шаблон списка',
                $templateStudentList,
                $before,
                $this->listState($templateStudentList->refresh())
            );
        });

        return response()->json(['item' => $this->payload($templateStudentList->refresh())]);
    }

    public function destroy(Request $request, TemplateStudentList $templateStudentList): JsonResponse
    {
        abort_unless((int) $templateStudentList->user_id === $this->ownerId($request), 403);

        DB::transaction(function () use ($request, $templateStudentList): void {
            if (ListTournament::where('template_student_list_id', $templateStudentList->id)->exists()) {
                throw ValidationException::withMessages(['list' => __('lists.attached_template')]);
            }
            $before = $this->listState($templateStudentList);

            $templateStudentList->delete();

            $this->writeActivityLog(
                $request,
                'template_student_list.deleted',
                'Удален шаблон списка',
                $templateStudentList,
                $before,
                null
            );
        });

        return response()->json(['message' => 'ok']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ownerId = $this->ownerId($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'start_order' => ['sometimes', 'integer', 'min:1'],
        ]);

        $allowedIds = TemplateStudentList::query()
            ->where('user_id', $ownerId)
            ->whereIn('id', $data['ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        abort_if(count($allowedIds) !== count($data['ids']), 403);

        $before = TemplateStudentList::query()
            ->where('user_id', $ownerId)
            ->whereIn('id', $data['ids'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TemplateStudentList $list): array => $this->listState($list))
            ->values()
            ->all();

        DB::transaction(function () use ($data, $request, $ownerId, $before) {
            $startOrder = (int) ($data['start_order'] ?? 1);

            foreach (array_values($data['ids']) as $index => $id) {
                TemplateStudentList::query()
                    ->whereKey($id)
                    ->update(['sort_order' => $startOrder + $index]);
            }

            $after = TemplateStudentList::query()
                ->where('user_id', $ownerId)
                ->whereIn('id', $data['ids'])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (TemplateStudentList $list): array => $this->listState($list))
                ->values()
                ->all();

            DB::table('activity_log')->insert([
                'log_name' => 'panel',
                'description' => 'Изменен порядок шаблонов списков',
                'subject_type' => TemplateStudentList::class,
                'subject_id' => null,
                'event' => 'template_student_list.reordered',
                'causer_type' => get_class($request->user()),
                'causer_id' => $request->user()->id,
                'properties' => json_encode([
                    'organization_id' => $ownerId,
                    'old' => $before,
                    'new' => $after,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'ok']);
    }

    private function applyFilters($query, Request $request): void
    {
        $query
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));

                $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->filled('list_type'), fn ($query) => $query->where('list_type', $request->string('list_type')))
            ->when($request->filled('kata_type'), fn ($query) => $query->where('kata_type', $request->string('kata_type')))
            ->when($request->filled('gender'), fn ($query) => $query->where('gender', $request->string('gender')))
            ->when($request->filled('age_from'), fn ($query) => $query->where('age_from', '>=', $request->integer('age_from')))
            ->when($request->filled('age_to'), fn ($query) => $query->where('age_to', '<=', $request->integer('age_to')))
            ->when($request->filled('weight_from'), fn ($query) => $query->where('weight_from', '>=', $request->integer('weight_from')))
            ->when($request->filled('weight_to'), fn ($query) => $query->where('weight_to', '<=', $request->integer('weight_to')))
            ->when($request->filled('rang_from'), fn ($query) => $query->where('rang_from', '>=', $request->integer('rang_from')))
            ->when($request->filled('rang_to'), fn ($query) => $query->where('rang_to', '<=', $request->integer('rang_to')));
    }

    private function validatedData(Request $request): array
    {
        return app(TemplateListInput::class)->validated($request);
    }

    private function writeActivityLog(
        Request $request,
        string $event,
        string $description,
        TemplateStudentList $list,
        ?array $old,
        ?array $new
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'panel',
            'description' => $description,
            'subject_type' => TemplateStudentList::class,
            'subject_id' => $list->id,
            'event' => $event,
            'causer_type' => get_class($request->user()),
            'causer_id' => $request->user()->id,
            'properties' => json_encode([
                'organization_id' => $this->ownerId($request),
                'template_student_list' => [
                    'id' => $list->id,
                    'name' => $list->name,
                ],
                'old' => $old,
                'new' => $new,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function listState(TemplateStudentList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'age_from' => $list->age_from,
            'age_to' => $list->age_to,
            'weight_from' => $list->weight_from,
            'weight_to' => $list->weight_to,
            'rang_from' => $list->rang_from,
            'rang_to' => $list->rang_to,
            'gender' => $list->gender,
            'user_id' => $list->user_id,
            'list_type' => $list->list_type,
            'kata_type' => $list->kata_type,
            'sort_order' => $list->sort_order,
        ];
    }

    private function activeFiltersCount(Request $request): int
    {
        return collect([
            'search',
            'list_type',
            'kata_type',
            'gender',
            'age_from',
            'age_to',
            'weight_from',
            'weight_to',
            'rang_from',
            'rang_to',
        ])->filter(fn (string $key) => $request->filled($key))->count();
    }

    private function ownerId(Request $request): int
    {
        $user = $request->user();

        return (int) ($user->organization_id ?: $user->id);
    }

    private function payload(TemplateStudentList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'age_from' => $list->age_from,
            'age_to' => $list->age_to,
            'weight_from' => $list->weight_from,
            'weight_to' => $list->weight_to,
            'rang_from' => $list->rang_from,
            'rang_to' => $list->rang_to,
            'gender' => $list->gender,
            'list_type' => $list->list_type,
            'kata_type' => $list->kata_type,
            'sort_order' => $list->sort_order,
        ];
    }
}
