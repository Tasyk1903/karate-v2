<?php

namespace App\Services\Tournaments;

use App\Models\Pool;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;

class BracketService
{
    public function bracket(Tournament $tournament, int $listId): array
    {
        $tournament->load([
            'pools' => function ($q) use ($listId, $tournament) {
                $q->where('list_id', $listId)
                    ->where('tournament_id', $tournament->id)
                    ->orderBy('round')
                    ->orderBy('position_in_round')
                    ->orderBy('id');
            },
            'pools.student.coach', 'pools.opponent.coach', 'pools.listTournament.templateStudentList',
        ]);

        // собрать id всех участников из пулов
        $participantIds = $tournament->pools
            ->flatMap(fn ($p) => [$p->student_id, $p->opponent_id])
            ->filter()
            ->unique()
            ->values();

        // разом вытащить статусы веса (без N+1)
        $weights = StudentTournament::where('tournament_id', $tournament->id)
            ->whereIn('student_id', $participantIds)
            ->pluck('is_success_weight', 'student_id'); // [student_id => 0/1]

        $isKata = (int) $tournament->tournament_type === Tournament::KATA;
        $labels = new BracketParticipantLabels($tournament, $tournament->pools
            ->flatMap(fn (Pool $pool) => [$pool->student, $pool->opponent])->filter()->unique('id'));

        return [
            'revision' => BracketState::version($tournament->pools),
            'can_swap' => app(BracketSwapService::class)->available($tournament->pools),
            'swap_participant_ids' => collect((new BracketTopology($tournament->pools))->seeds())->pluck('id')->values(),
            'tournament' => [
                'id' => $tournament->id,
                'organization_id' => $tournament->organization_id,
                'fight_for_third_place' => (bool) $tournament->fight_for_third_place,
                'is_kata' => $isKata,                                 // <— добавили
                'titleList' => $tournament->pools->first()?->listTournament?->templateStudentList?->name ?? 'Default Title',
                'pools' => $tournament->pools->map(function ($p) use ($weights, $labels) {
                    $stud = $p->student ? [
                        'id' => $p->student->id,
                        'first_name' => $p->student->first_name,
                        'last_name' => $p->student->last_name,
                        'coach_line' => $labels->line($p->student),
                        'avatar' => $p->student->avatar,
                        'is_success_weight' => (bool) ($weights[$p->student->id] ?? false),  // <—
                    ] : null;

                    $opp = $p->opponent ? [
                        'id' => $p->opponent->id,
                        'first_name' => $p->opponent->first_name,
                        'last_name' => $p->opponent->last_name,
                        'coach_line' => $labels->line($p->opponent),
                        'avatar' => $p->opponent->avatar,
                        'is_success_weight' => (bool) ($weights[$p->opponent->id] ?? false), // <—
                    ] : null;

                    return [
                        'id' => $p->id,
                        'round' => $p->round,
                        'position_in_round' => $p->position_in_round,
                        'type' => $p->type,
                        'tatami_and_fight_number' => $p->tatami_and_fight_number,
                        'student_id' => $p->student_id,
                        'opponent_id' => $p->opponent_id,
                        'winner_id' => $p->winner_id,
                        'winner_id_1rd_robbin' => $p->winner_id_1rd_robbin,
                        'winner_id_2rd_robbin' => $p->winner_id_2rd_robbin,
                        'winner_id_3rd_robbin' => $p->winner_id_3rd_robbin,
                        'student' => $stud,
                        'opponent' => $opp,
                        'absent_student' => (bool) $p->absent_student,
                        'absent_opponent' => (bool) $p->absent_opponent,
                        'student_wazari_count' => (int) ($p->student_wazari_count ?? 0),
                        'opponent_wazari_count' => (int) ($p->opponent_wazari_count ?? 0),
                        'student_ippon' => (bool) ($p->student_ippon ?? false),
                        'opponent_ippon' => (bool) ($p->opponent_ippon ?? false),
                    ];
                })->values(),
            ],
        ];
    }

    public function updateTatami(Pool $pool, ?string $value, ?string $revision = null): string
    {
        return app(BracketMutation::class)->run($pool->tournament_id, $pool->list_id, $this->actor(), $revision, 'tournament.pool.tatami_updated', ['pool_id' => $pool->id],
            function ($pools) use ($pool, $value) {
                $target = $pools->firstWhere('id', $pool->id);
                abort_unless($target, 404);
                $target->tatami_and_fight_number = $value;
            });
    }

    public function setWinner(Pool $pool, int $winnerId, array $scores = [], ?string $revision = null): string
    {
        return app(FightResultService::class)->save($pool, $this->actor(), $revision, $winnerId, $scores, null);
    }

    public function setAbsences(Pool $pool, array $absentIds, ?string $revision = null): string
    {
        return app(FightResultService::class)->save($pool, $this->actor(), $revision, null, [], $absentIds);
    }

    public function setRoundRobinWinners(Tournament $tournament, int $listId, array $ids, ?string $revision = null): string
    {
        return app(RoundRobinResultService::class)->save($tournament, $listId, $this->actor(), $revision, $ids);
    }

    public function swapParticipants(Tournament $tournament, int $listId, int $participant1, int $participant2, array $poolIds, ?string $revision = null): string
    {
        return app(BracketSwapService::class)->swap($tournament, $listId, $this->actor(), $revision, $participant1, $participant2, $poolIds);
    }

    private function actor(): User
    {
        abort_unless(auth()->user() instanceof User, 403);

        return auth()->user();
    }
}
