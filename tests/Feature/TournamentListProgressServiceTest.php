<?php

namespace Tests\Feature;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\TournamentListProgressService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentListProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
    }

    protected function tearDown(): void
    {
        Model::reguard();

        parent::tearDown();
    }

    public function test_kumite_progress_matches_old_list_logic(): void
    {
        [$tournament, $list] = $this->tournamentWithList(Tournament::KUMITE);
        $ids = $this->participantIds(5);

        Pool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $ids[0],
            'opponent_id' => $ids[1],
            'winner_id' => $ids[0],
            'round' => 1,
            'position_in_round' => 1,
        ]);
        Pool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $ids[2],
            'opponent_id' => $ids[3],
            'round' => 1,
            'position_in_round' => 2,
            'absent_opponent' => true,
        ]);
        Pool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => null,
            'opponent_id' => $ids[4],
            'round' => 2,
            'position_in_round' => 1,
        ]);

        $stats = app(TournamentListProgressService::class)->statsFor($tournament, collect([$list->id]));

        $this->assertSame(3, $stats[$list->id]['generated_count']);
        $this->assertSame(2, $stats[$list->id]['total_count']);
        $this->assertSame(2, $stats[$list->id]['completed_count']);
        $this->assertSame(100, $stats[$list->id]['completion_percent']);
    }

    public function test_round_robin_progress_matches_old_list_logic(): void
    {
        [$tournament, $list] = $this->tournamentWithList(Tournament::KUMITE);
        $ids = $this->participantIds(6);

        foreach ([1, 2, 3] as $position) {
            Pool::query()->create([
                'tournament_id' => $tournament->id,
                'list_id' => $list->id,
                'student_id' => $ids[$position - 1],
                'opponent_id' => $ids[$position + 2],
                'type' => 'Round Robin',
                'round' => 1,
                'position_in_round' => $position,
                'winner_id_1rd_robbin' => $ids[0],
                'winner_id_2rd_robbin' => $ids[1],
            ]);
        }

        $stats = app(TournamentListProgressService::class)->statsFor($tournament, collect([$list->id]));

        $this->assertSame(3, $stats[$list->id]['generated_count']);
        $this->assertSame(3, $stats[$list->id]['total_count']);
        $this->assertSame(3, $stats[$list->id]['completed_count']);
        $this->assertSame(100, $stats[$list->id]['completion_percent']);
    }

    public function test_point_kata_progress_counts_ranked_scored_rows(): void
    {
        [$tournament, $list] = $this->tournamentWithList(Tournament::KATA);
        $tournament->update(['tournament_type_kata' => Tournament::POINT_SYSTEM]);
        $ids = $this->participantIds(2);

        KataPool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $ids[0],
            'total_score' => 24.5,
            'rank' => 1,
        ]);
        KataPool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $ids[1],
        ]);

        $stats = app(TournamentListProgressService::class)->statsFor($tournament->refresh(), collect([$list->id]));

        $this->assertSame(2, $stats[$list->id]['generated_count']);
        $this->assertSame(2, $stats[$list->id]['total_count']);
        $this->assertSame(1, $stats[$list->id]['completed_count']);
        $this->assertSame(50, $stats[$list->id]['completion_percent']);
    }

    private function tournamentWithList(int $type): array
    {
        $organization = User::query()->create([
            'name' => 'Организация',
            'email' => uniqid('org', true) . '@example.test',
            'password' => 'password',
        ]);
        $tournament = Tournament::query()->create([
            'name' => 'Тестовый турнир',
            'organization_id' => $organization->id,
            'tournament_type' => $type,
            'tournament_type_kata' => $type === Tournament::KATA ? Tournament::POINT_SYSTEM : null,
            'age_from' => 8,
            'age_to' => 9,
            'tatami' => 1,
            'price' => 0,
            'date_commission' => '2026-05-01 10:00:00',
            'date' => '2026-05-02',
            'date_finish' => '2026-05-02',
            'address' => 'Ростов-на-Дону',
        ]);
        $template = TemplateStudentList::query()->create([
            'name' => 'Мальчики 8-9 лет',
            'user_id' => $organization->id,
        ]);
        $list = ListTournament::query()->create([
            'tournament_id' => $tournament->id,
            'template_student_list_id' => $template->id,
        ]);

        return [$tournament, $list];
    }

    private function participantIds(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index) => User::query()->create([
                'name' => "Участник {$index}",
                'email' => uniqid("student{$index}", true) . '@example.test',
                'password' => 'password',
            ])->id)
            ->all();
    }
}
