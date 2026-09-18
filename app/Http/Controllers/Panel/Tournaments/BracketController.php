<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\KataPool;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Exports\GenerationSnapshot;
use App\Services\Exports\PanelTasks;
use App\Services\Tournaments\BracketService;
use App\Services\Tournaments\BracketState;
use App\Services\Tournaments\PoolGenerationService;
use App\Services\Tournaments\StudentTournamentEnrollment;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BracketController extends Controller
{
    public function show(Request $request, Championship $championship, Tournament $tournament, int $list): JsonResponse
    {
        $this->authorizeTournamentView($request->user(), $championship, $tournament);
        abort_unless(! ((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM), 404);

        abort_unless(DB::table('list_tournaments')->where('id', $list)->where('tournament_id', $tournament->id)->exists(), 404);

        return response()->json(app(BracketService::class)->bracket($tournament, $list));
    }

    public function generate(Request $request, Championship $championship, Tournament $tournament, PoolGenerationService $generator): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $data = $request->validate([
            'list_id' => ['nullable', 'integer', 'exists:list_tournaments,id'],
        ]);

        abort_if(
            empty($data['list_id']) && ! TournamentLifecycle::commissionOpen($tournament),
            403,
            __('exports.commission_closed')
        );

        if (! empty($data['list_id'])) {
            abort_unless(DB::table('list_tournaments')
                ->where('id', $data['list_id'])
                ->where('tournament_id', $tournament->id)
                ->exists(), 404);
        }

        if (empty($data['list_id']) && PanelTasks::shouldDefer($request)) {
            return app(PanelTasks::class)->defer($request, 'generate', [
                'championship' => $championship->id, 'tournament' => $tournament->id,
                'revision' => GenerationSnapshot::hash($tournament),
            ]);
        }

        $result = DB::transaction(function () use ($request, $championship, $tournament, $data, $generator) {
            $locked = Tournament::lockForUpdate()->findOrFail($tournament->id);
            $this->authorizeTournamentManage($request->user(), $championship, $locked);
            abort_if(empty($data['list_id']) && ! TournamentLifecycle::commissionOpen($locked), 403);
            if ($expected = $request->attributes->get('generation_revision')) {
                abort_unless(hash_equals($expected, GenerationSnapshot::hash($locked, true)), 409);
            }
            $query = Pool::where('tournament_id', $locked->id)->when($data['list_id'] ?? null, fn ($q, $id) => $q->where('list_id', $id));
            $old = BracketState::snapshot((clone $query)->lockForUpdate()->get());
            $kataQuery = KataPool::where('tournament_id', $locked->id)->when($data['list_id'] ?? null, fn ($q, $id) => $q->where('list_id', $id));
            $kataOld = (clone $kataQuery)->orderBy('id')->lockForUpdate()->get()->toArray();
            $result = $generator->generate($locked->id, $data['list_id'] ?? null);
            $this->writeActivity($request->user(), 'Пули/таблицы турнира сгенерированы', 'tournament.brackets.generated', $locked, [
                'result' => $result, 'list_id' => $data['list_id'] ?? null, 'old' => $old,
                'new' => BracketState::snapshot($query->get()), 'kata_old' => $kataOld, 'kata_new' => $kataQuery->orderBy('id')->get()->toArray(),
            ]);

            return $result;
        }, 3);

        return response()->json($result);
    }

    public function updateTatami(Request $request, Championship $championship, Tournament $tournament, Pool $pool, BracketService $brackets): JsonResponse
    {
        $this->authorizePoolManage($request->user(), $championship, $tournament, $pool);

        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $revision = $brackets->updateTatami($pool, $data['value'] ?? null, $this->revision($request));

        return response()->json(['ok' => true, 'revision' => $revision]);
    }

    public function setWinner(Request $request, Championship $championship, Tournament $tournament, Pool $pool, BracketService $brackets): JsonResponse
    {
        $this->authorizePoolManage($request->user(), $championship, $tournament, $pool);

        $data = $request->validate([
            'winner_id' => ['required', 'integer'],
            'student_wazari_count' => ['nullable', 'integer', 'min:0', 'max:2'],
            'opponent_wazari_count' => ['nullable', 'integer', 'min:0', 'max:2'],
            'student_ippon' => ['nullable', 'boolean'],
            'opponent_ippon' => ['nullable', 'boolean'],
        ]);

        $revision = $brackets->setWinner($pool, (int) $data['winner_id'], $data, $this->revision($request));

        return response()->json(['ok' => true, 'revision' => $revision]);
    }

    public function setAbsences(Request $request, Championship $championship, Tournament $tournament, Pool $pool, BracketService $brackets): JsonResponse
    {
        $this->authorizePoolManage($request->user(), $championship, $tournament, $pool);

        $data = $request->validate([
            'absent_ids' => ['present', 'array', 'max:2'],
            'absent_ids.*' => ['integer', 'distinct'],
        ]);

        $revision = $brackets->setAbsences($pool, $data['absent_ids'] ?? [], $this->revision($request));

        return response()->json(['ok' => true, 'revision' => $revision]);
    }

    public function setRoundRobinWinners(Request $request, Championship $championship, Tournament $tournament, int $list, BracketService $brackets): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $data = $request->validate([
            'winner_id_1rd_robbin' => ['required', 'integer'],
            'winner_id_2rd_robbin' => ['required', 'integer'],
            'winner_id_3rd_robbin' => ['nullable', 'integer'],
            'pool_ids' => ['required', 'array', 'min:1'],
            'pool_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $revision = $brackets->setRoundRobinWinners($tournament, $list, $data, $this->revision($request));

        return response()->json(['ok' => true, 'revision' => $revision]);
    }

    public function swapParticipants(Request $request, Championship $championship, Tournament $tournament, int $list, BracketService $brackets): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $data = $request->validate([
            'participant_1' => ['required', 'integer'],
            'participant_2' => ['required', 'integer'],
            'pool_ids' => ['required', 'array', 'min:1'],
            'pool_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $revision = $brackets->swapParticipants($tournament, $list, (int) $data['participant_1'], (int) $data['participant_2'], $data['pool_ids'], $this->revision($request));

        return response()->json(['ok' => true, 'revision' => $revision]);
    }

    private function revision(Request $request): string
    {
        return $request->validate(['revision' => ['required', 'string', 'size:64']])['revision'];
    }

    private function authorizePoolManage(User $user, Championship $championship, Tournament $tournament, Pool $pool): void
    {
        $this->authorizeTournamentManage($user, $championship, $tournament);
        abort_unless((int) $pool->tournament_id === (int) $tournament->id, 404);
    }

    private function authorizeTournamentView(User $user, Championship $championship, Tournament $tournament): void
    {
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);

        if ($user->hasProjectRole('Student')) {
            abort_unless(app(StudentTournamentEnrollment::class)->admitted($user, $tournament), 403);

            return;
        }

        app()->setLocale(request()->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
        $organizationId = $this->organizationId($user);

        abort_unless(
            (int) $championship->organization_id === (int) $organizationId
            || (int) $tournament->organization_id === (int) $organizationId
            || DB::table('organization_tournaments')
                ->where('tournament_id', $tournament->id)
                ->where('applicant_organizer_id', $organizationId)
                ->where('is_success', 'accepted')
                ->exists(),
            403
        );
    }

    private function authorizeTournamentManage(User $user, Championship $championship, Tournament $tournament): void
    {
        $this->authorizeTournamentView($user, $championship, $tournament);
        abort_unless($user->hasAnyProjectRole(['Organization', 'Secretary']), 403);
        abort_unless((int) $tournament->organization_id === (int) $this->organizationId($user), 403);
        abort_unless(TournamentLifecycle::canManage($user, $tournament), 403);
    }

    private function organizationId(User $user): ?int
    {
        return $user->hasProjectRole('Organization') ? $user->id : $user->organization_id;
    }

    private function writeActivity(User $causer, string $description, string $event, Tournament $tournament, array $properties): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'panel',
            'description' => $description,
            'subject_type' => Tournament::class,
            'subject_id' => $tournament->id,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $causer->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
