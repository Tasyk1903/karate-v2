<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\ListTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Services\Tournaments\ListCompatibility;
use App\Services\Tournaments\TemplateListInput;
use App\Services\Tournaments\TournamentApplications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TournamentListController extends BaseTournamentController
{
    public function storeList(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $data = $this->validatedTemplateListData($request);
        app(ListCompatibility::class)->assert(new TemplateStudentList($data), $tournament);
        $data['user_id'] = $this->organizationId($request->user());
        $data['sort_order'] = ((int) TemplateStudentList::query()
            ->where('user_id', $data['user_id'])
            ->max('sort_order')) + 1;

        DB::transaction(function () use ($request, $tournament, $data): void {
            $list = TemplateStudentList::create($data);

            DB::table('list_tournaments')->insert([
                'tournament_id' => $tournament->id,
                'template_student_list_id' => $list->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->writeTournamentActivity(
                $request->user(),
                'Список создан и прикреплен к турниру',
                'tournament.list.created_attached',
                TemplateStudentList::class,
                $list->id,
                [
                    'tournament' => $this->tournamentSnapshot($tournament),
                    'new' => $list->only([
                        'id',
                        'name',
                        'age_from',
                        'age_to',
                        'weight_from',
                        'weight_to',
                        'rang_from',
                        'rang_to',
                        'gender',
                        'user_id',
                        'list_type',
                        'kata_type',
                        'sort_order',
                    ]),
                ]
            );
        });

        return $this->showTournament($request, $championship, $tournament);
    }

    public function attachLists(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $data = $request->validate([
            'template_ids' => ['required', 'array', 'min:1'],
            'template_ids.*' => ['integer', 'distinct'],
        ]);

        $templates = TemplateStudentList::query()
            ->where('user_id', $this->organizationId($request->user()))
            ->whereIn('id', $data['template_ids'])
            ->get();
        abort_unless($templates->count() === count($data['template_ids']), 422, __('lists.incompatible'));
        foreach ($templates as $template) {
            app(ListCompatibility::class)->assert($template, $tournament);
        }
        $allowedIds = $templates->pluck('id');

        DB::transaction(function () use ($allowedIds, $request, $tournament): void {
            $attached = [];
            foreach ($allowedIds as $templateId) {
                DB::table('list_tournaments')->updateOrInsert(
                    ['tournament_id' => $tournament->id, 'template_student_list_id' => $templateId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
                $attached[] = (int) $templateId;
            }

            if ($attached !== []) {
                $this->writeTournamentActivity(
                    $request->user(),
                    'Списки прикреплены к турниру',
                    'tournament.lists.attached',
                    Tournament::class,
                    $tournament->id,
                    [
                        'tournament' => $this->tournamentSnapshot($tournament),
                        'template_ids' => $attached,
                    ]
                );
            }

        });

        return $this->showTournament($request, $championship, $tournament);
    }

    public function detachList(Request $request, Championship $championship, Tournament $tournament, int $listTournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        app(\App\Services\Tournaments\TournamentBulkActions::class)->detach($request->user(), $tournament, 'lists', [$listTournament]);
        return $this->showTournament($request, $championship, $tournament);
    }

    public function updateTatami(Request $request, Championship $championship, Tournament $tournament, int $listTournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $tatamiOptions = collect(range(1, max(1, (int) $tournament->tatami)))
            ->map(fn (int $index): string => chr(64 + $index))
            ->all();

        $data = $request->validate([
            'tatami' => ['nullable', 'string', Rule::in($tatamiOptions)],
        ]);

        $list = DB::table('list_tournaments')
            ->where('id', $listTournament)
            ->where('tournament_id', $tournament->id)
            ->first();

        abort_unless($list, 404);

        $tatami = $data['tatami'] ?? null;
        $old = ['tatami' => $list->tatami];
        $isPointKata = (int) $tournament->tournament_type === Tournament::KATA
            && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM;

        DB::transaction(function () use ($request, $tournament, $listTournament, $tatami, $old, $isPointKata): void {
            DB::table('list_tournaments')
                ->where('id', $listTournament)
                ->update(['tatami' => $tatami, 'updated_at' => now()]);

            DB::table($isPointKata ? 'kata_pools' : 'pools')
                ->where('tournament_id', $tournament->id)
                ->where('list_id', $listTournament)
                ->update(['tatami' => $tatami, 'updated_at' => now()]);

            $this->writeTournamentActivity(
                $request->user(),
                'Назначен татами для пули/таблицы',
                'tournament.list.tatami_updated',
                Tournament::class,
                $tournament->id,
                [
                    'tournament' => $this->tournamentSnapshot($tournament),
                    'list_tournament_id' => $listTournament,
                    'old' => $old,
                    'new' => ['tatami' => $tatami],
                ]
            );
        });

        return response()->json(['tatami' => $tatami]);
    }

    private function validatedTemplateListData(Request $request): array
    {
        return app(TemplateListInput::class)->validated($request);
    }
}
