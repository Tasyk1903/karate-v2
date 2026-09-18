<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\Region;
use App\Models\Scale;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\BracketService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BracketServiceTest extends TestCase
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

    public function test_region_replaces_club_only_from_regional_scale_upwards(): void
    {
        [$tournament, $list] = $this->tournamentWithList();
        [$a, $b, $coachId] = $this->participantIds(3);
        $region = Region::create(['name' => 'Ростовская область']);
        User::findOrFail($coachId)->update(['club' => 'Coach club', 'last_name' => 'Coach', 'first_name' => 'Test']);
        User::whereIn('id', [$a, $b])->update(['coach_id' => $coachId, 'club' => 'Wrong student club']);
        User::findOrFail($a)->update(['region_id' => $region->id]);
        $this->pool($tournament, $list, ['student_id' => $a, 'opponent_id' => $b, 'type' => 'final']);
        $url = '/api/panel/tournaments/'.$tournament->championship_id.'/items/'.$tournament->id.'/brackets/'.$list->id;
        foreach ([null, Scale::CLOSED_CLUB, Scale::INTERCLUB, Scale::CITY, Scale::REGION, Scale::FEDERAL_DISTRICT, Scale::ALL_RUSSIAN, Scale::RUSSIAN_CHAMPIONSHIP, Scale::INTERNATIONAL] as $slug) {
            $scale = $slug ? Scale::firstOrCreate(['slug' => $slug], ['name' => $slug]) : null;
            $tournament->update(['scale_id' => $scale?->id]);
            $regional = ! in_array($slug, [null, Scale::CLOSED_CLUB, Scale::INTERCLUB, Scale::CITY], true);
            $response = $this->getJson($url.'?locale=en')->assertOk();
            $response->assertJsonPath('tournament.pools.0.student.coach_line', ($regional ? $region->name : 'Coach club').' Coach T.');
            $response->assertJsonPath('tournament.pools.0.opponent.coach_line', ($regional ? 'Region not specified' : 'Coach club').' Coach T.');
        }
        $this->assertDatabaseHas('users', ['id' => $a, 'club' => 'Wrong student club', 'region_id' => $region->id]);
    }

    public function test_semifinal_winner_moves_to_final_and_loser_moves_to_third_place(): void
    {
        [$tournament, $list] = $this->tournamentWithList(fightForThirdPlace: true);
        [$winner, $loser] = $this->participantIds(2);
        $semifinal = $this->pool($tournament, $list, [
            'student_id' => $winner,
            'opponent_id' => $loser,
            'round' => 1,
            'position_in_round' => 1,
            'type' => '1/2',
        ]);
        $final = $this->pool($tournament, $list, [
            'round' => 2,
            'position_in_round' => 1,
            'type' => 'final',
        ]);

        app(BracketService::class)->setWinner($semifinal, $winner, [
            'student_wazari_count' => 1,
            'student_ippon' => false,
        ]);

        $this->assertSame($winner, $semifinal->refresh()->winner_id);
        $this->assertSame($winner, $final->refresh()->student_id);
        $this->assertNull($final->winner_id);

        $third = Pool::query()
            ->where('tournament_id', $tournament->id)
            ->where('list_id', $list->id)
            ->where('type', '3rd')
            ->first();

        $this->assertNotNull($third);
        $this->assertSame($loser, $third->student_id);
        $this->assertNull($third->opponent_id);
    }

    public function test_single_absence_advances_present_participant_without_third_place_loser(): void
    {
        [$tournament, $list] = $this->tournamentWithList(fightForThirdPlace: true);
        [$present, $absent] = $this->participantIds(2);
        $semifinal = $this->pool($tournament, $list, [
            'student_id' => $present,
            'opponent_id' => $absent,
            'round' => 1,
            'position_in_round' => 1,
            'type' => '1/2',
        ]);
        $final = $this->pool($tournament, $list, [
            'round' => 2,
            'position_in_round' => 1,
            'type' => 'final',
        ]);

        app(BracketService::class)->setAbsences($semifinal, [$absent]);

        $this->assertTrue((bool) $semifinal->refresh()->absent_opponent);
        $this->assertNull($semifinal->winner_id);
        $this->assertSame($present, $final->refresh()->student_id);

        $this->assertFalse(Pool::query()
            ->where('tournament_id', $tournament->id)
            ->where('list_id', $list->id)
            ->where('type', '3rd')
            ->exists());
    }

    public function test_round_robin_winners_are_written_to_all_category_fights(): void
    {
        [$tournament, $list] = $this->tournamentWithList();
        [$first, $second, $third] = $this->participantIds(3);
        $pools = collect(range(1, 3))
            ->map(fn (int $position) => $this->pool($tournament, $list, [
                'student_id' => $position === 3 ? $second : $first,
                'opponent_id' => $position === 1 ? $second : $third,
                'round' => 1,
                'position_in_round' => $position,
                'type' => 'Round Robin',
            ]));

        app(BracketService::class)->setRoundRobinWinners($tournament, $list->id, [
            'pool_ids' => $pools->pluck('id')->all(),
            'winner_id_1rd_robbin' => $first,
            'winner_id_2rd_robbin' => $second,
            'winner_id_3rd_robbin' => $third,
        ]);

        $pools->each(function (Pool $pool) use ($first, $second, $third): void {
            $pool->refresh();

            $this->assertSame($first, $pool->winner_id_1rd_robbin);
            $this->assertSame($second, $pool->winner_id_2rd_robbin);
            $this->assertSame($third, $pool->winner_id_3rd_robbin);
        });
    }

    private function tournamentWithList(bool $fightForThirdPlace = false): array
    {
        $role = DB::table('roles')->insertGetId(['name' => 'Organization', 'guard_name' => 'web']);
        DB::table('old_roles')->insert(['id' => $role, 'name' => 'Organization']);
        $organization = User::query()->create([
            'role_id' => $role,
            'name' => 'Организация',
            'email' => uniqid('org', true).'@example.test',
            'password' => 'password',
        ]);
        $this->actingAs($organization);
        $champ = Championship::create(['name' => 'Championship', 'organization_id' => $organization->id, 'banner' => 'test.jpg']);
        $tournament = Tournament::query()->create([
            'championship_id' => $champ->id,
            'name' => 'Тестовый турнир',
            'organization_id' => $organization->id,
            'tournament_type' => Tournament::KUMITE,
            'age_from' => 8,
            'age_to' => 9,
            'tatami' => 1,
            'price' => 0,
            'fight_for_third_place' => $fightForThirdPlace,
            'date_commission' => '2026-05-01 10:00:00',
            'date' => '2026-05-02',
            'date_finish' => now()->addDay()->toDateString(),
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
                'email' => uniqid("student{$index}", true).'@example.test',
                'password' => 'password',
            ])->id)
            ->all();
    }

    private function pool(Tournament $tournament, ListTournament $list, array $attributes = []): Pool
    {
        return Pool::query()->create(array_merge([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'round' => 1,
            'position_in_round' => 1,
        ], $attributes));
    }
}
