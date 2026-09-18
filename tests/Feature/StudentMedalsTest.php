<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\Pool;
use App\Models\Scale;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\StudentMedalService;
use App\Services\Tournaments\PanelTournamentVisibility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentMedalsTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $coach;

    private User $student;

    private User $opponent;

    private Championship $championship;

    private Scale $scale;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Carbon::setTestNow('2026-09-08');
        foreach (['Admin', 'Organization', 'Secretary', 'Coach', 'Student', 'Judge'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization');
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        $this->student = $this->user('Student', ['coach_id' => $this->coach->id]);
        $this->opponent = $this->user('Student');
        $this->championship = Championship::create(['name' => 'Championship', 'organization_id' => $this->org->id, 'banner' => 'test.jpg']);
        $this->scale = Scale::create(['name' => 'City', 'slug' => Scale::CITY, 'is_rating' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::create($attributes + ['first_name' => 'Alex', 'last_name' => 'Example', 'email' => Str::uuid().'@test.example', 'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id'), 'birthday' => '2016-01-01', 'gender' => 'm', 'rang' => '5 kyu', 'weight' => 30]);
    }

    private function tournament(array $attributes = []): Tournament
    {
        return Tournament::create($attributes + ['name' => 'Tournament', 'organization_id' => $this->org->id, 'championship_id' => $this->championship->id, 'scale_id' => $this->scale->id, 'tournament_type' => Tournament::KUMITE, 'tournament_type_kata' => Tournament::FLAG_SYSTEM, 'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'date' => '2026-06-01', 'date_commission' => '2026-06-01', 'date_finish' => '2026-06-02', 'address' => 'City']);
    }

    private function list(Tournament $tournament): ListTournament
    {
        $template = TemplateStudentList::create(['name' => '10-11', 'user_id' => $this->org->id, 'list_type' => $tournament->tournament_type === Tournament::KATA ? 'kata' : 'kumite', 'kata_type' => 'personal']);

        return ListTournament::create(['tournament_id' => $tournament->id, 'template_student_list_id' => $template->id]);
    }

    private function pool(Tournament $tournament, array $attributes = []): Pool
    {
        return Pool::create($attributes + ['tournament_id' => $tournament->id, 'list_id' => $this->list($tournament)->id, 'type' => 'final', 'round' => 1, 'student_id' => $this->student->id, 'opponent_id' => $this->opponent->id, 'winner_id' => $this->student->id]);
    }

    private function kata(Tournament $tournament, array $attributes = []): KataPool
    {
        return KataPool::create($attributes + ['tournament_id' => $tournament->id, 'list_id' => $this->list($tournament)->id, 'round' => 'FINAL', 'student_id' => $this->student->id, 'winner_1' => true]);
    }

    private function medals(): array
    {
        return app(StudentMedalService::class)->forStudent($this->student->fresh());
    }

    private function zero(): array
    {
        return ['gold' => 0, 'silver' => 0, 'bronze' => 0];
    }

    public function test_kumite_medals_are_distinct_per_tournament_and_each_award_not_per_list(): void
    {
        $gold = $this->tournament();
        $silver = $this->tournament();
        $bronze = $this->tournament();
        foreach (range(1, 3) as $_) {
            $this->pool($gold);
            $this->pool($silver, ['winner_id' => $this->opponent->id]);
            $this->pool($bronze, ['type' => '3rd']);
        }
        $this->assertSame(['gold' => 1, 'silver' => 1, 'bronze' => 1], $this->medals()['kumite']);
        $this->assertSame($this->zero(), $this->medals()['kata']);
    }

    public function test_unfinished_or_corrupt_finals_and_preliminary_wins_give_no_medals(): void
    {
        foreach ([Tournament::KUMITE, Tournament::KATA] as $discipline) {
            $tournament = $this->tournament(['tournament_type' => $discipline]);
            foreach ([null, $this->coach->id] as $winner) {
                $this->pool($tournament, ['winner_id' => $winner]);
            }
            $this->pool($tournament, ['type' => '1/2']);
            $this->pool($tournament, ['student_id' => $this->coach->id, 'opponent_id' => $this->opponent->id]);
            $this->pool($tournament, ['type' => '3rd', 'student_id' => $this->coach->id]);
        }
        $this->assertSame(['kumite' => $this->zero(), 'kata' => $this->zero()], $this->medals());
    }

    public function test_only_allowed_scales_live_competitions_and_history_dates_count(): void
    {
        $this->student->update(['competitive_record_starts_at' => '2026-01-01']);
        foreach ([Scale::CLOSED_CLUB, Scale::INTERCLUB, 'unknown'] as $slug) {
            $scale = Scale::create(['name' => $slug, 'slug' => $slug, 'is_rating' => true]);
            $this->pool($this->tournament(['scale_id' => $scale->id]));
        }
        $this->pool($this->tournament(['date' => '2025-12-31']));
        $deleted = $this->tournament();
        $this->pool($deleted);
        $deleted->delete();
        $champ = Championship::create(['name' => 'Deleted', 'organization_id' => $this->org->id, 'banner' => 'test.jpg']);
        $this->pool($this->tournament(['championship_id' => $champ->id]));
        $champ->delete();
        $this->assertSame($this->zero(), $this->medals()['kumite']);
        foreach ([Scale::CITY, Scale::REGION, Scale::FEDERAL_DISTRICT, Scale::ALL_RUSSIAN, Scale::INTERNATIONAL, Scale::RUSSIAN_CHAMPIONSHIP] as $slug) {
            $scale = Scale::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_rating' => false]);
            $this->pool($this->tournament(['scale_id' => $scale->id, 'date' => '2026-01-01']));
        }
        $this->assertSame(['gold' => 6, 'silver' => 0, 'bronze' => 0], $this->medals()['kumite']);
    }

    public function test_null_or_future_history_start_preserves_old_unrestricted_history_rule(): void
    {
        $this->pool($this->tournament(['date' => '2020-06-01']));
        $this->assertSame(1, $this->medals()['kumite']['gold']);
        $this->student->update(['competitive_record_starts_at' => '2027-01-01']);
        $this->assertSame(1, $this->medals()['kumite']['gold']);
        $this->student->update(['competitive_record_starts_at' => '2026-09-08']);
        $this->assertSame(0, $this->medals()['kumite']['gold']);
    }

    public function test_round_robin_podium_is_counted_once_per_tournament_not_once_per_bout(): void
    {
        foreach ([Tournament::KUMITE => 'kumite', Tournament::KATA => 'kata'] as $discipline => $key) {
            foreach ([1, 2, 3] as $place) {
                $tournament = $this->tournament(['tournament_type' => $discipline]);
                $list = $this->list($tournament);
                foreach (range(1, 3) as $_) {
                    $this->pool($tournament, ['list_id' => $list->id, 'type' => 'Round Robin', 'winner_id' => null, 'winner_id_'.$place.'rd_robbin' => $this->student->id]);
                }
                $this->pool($tournament, ['list_id' => $list->id, 'type' => 'Round Robin', 'student_id' => $this->coach->id, 'winner_id' => null, 'winner_id_'.$place.'rd_robbin' => $this->student->id]);
            }
            $this->assertSame(['gold' => 1, 'silver' => 1, 'bronze' => 1], $this->medals()[$key]);
        }
    }

    public function test_round_robin_fields_on_other_bouts_and_nonparticipants_do_not_award_medals(): void
    {
        $t = $this->tournament();
        $this->pool($t, ['type' => '1/4', 'winner_id_1rd_robbin' => $this->student->id]);
        $this->pool($t, ['type' => 'Round Robin', 'student_id' => $this->coach->id, 'winner_id' => null, 'winner_id_1rd_robbin' => $this->student->id]);
        $this->assertSame($this->zero(), $this->medals()['kumite']);
    }

    public function test_point_kata_includes_individual_and_group_members_without_double_counting_one_row(): void
    {
        $t = $this->tournament(['tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM]);
        $this->kata($t);
        $this->kata($t, ['student_id' => null, 'group_id' => 'team-1', 'students' => [$this->student->id, $this->opponent->id], 'winner_1' => false, 'winner_2' => true]);
        $this->kata($t, ['student_id' => $this->opponent->id, 'group_id' => 'team-2', 'students' => [(string) $this->student->id], 'winner_1' => false, 'winner_3' => true]);
        $this->kata($t, ['group_id' => 'team-3', 'students' => [$this->student->id]]);
        $this->assertSame(['gold' => 2, 'silver' => 1, 'bronze' => 1], $this->medals()['kata']);
        $this->assertSame($this->zero(), $this->medals()['kumite']);
    }

    public function test_flag_kata_uses_bracket_awards_without_polluting_kumite(): void
    {
        $t = $this->tournament(['tournament_type' => Tournament::KATA]);
        $this->pool($t);
        $this->pool($t);
        $this->pool($this->tournament(['tournament_type' => Tournament::KATA]), ['winner_id' => $this->opponent->id]);
        $this->pool($this->tournament(['tournament_type' => Tournament::KATA]), ['type' => '3rd']);
        $this->assertSame(['gold' => 1, 'silver' => 1, 'bronze' => 1], $this->medals()['kata']);
        $this->assertSame($this->zero(), $this->medals()['kumite']);
    }

    public function test_kata_excludes_wrong_discipline_subtype_preliminary_and_ineligible_competitions(): void
    {
        $this->student->update(['competitive_record_starts_at' => '2026-01-01']);
        $point = ['tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM];
        $this->kata($this->tournament());
        $this->kata($this->tournament(['tournament_type' => Tournament::KATA]));
        $this->pool($this->tournament($point));
        $this->kata($this->tournament($point), ['round' => 'PRELIMINARY STAGE']);
        $this->kata($this->tournament($point), ['winner_1' => false]);
        $this->kata($this->tournament($point + ['date' => '2025-12-31']));
        $closed = Scale::create(['name' => 'Club', 'slug' => Scale::CLOSED_CLUB]);
        $this->kata($this->tournament($point + ['scale_id' => $closed->id]));
        $t = $this->tournament($point);
        $this->kata($t);
        $t->delete();
        $this->assertSame(['kumite' => $this->zero(), 'kata' => $this->zero()], $this->medals());
        $this->kata($this->tournament($point));
        $this->championship->delete();
        $this->assertSame(['kumite' => $this->zero(), 'kata' => $this->zero()], $this->medals());
    }

    public function test_web_and_mobile_profiles_return_the_same_shared_medals(): void
    {
        $this->pool($this->tournament());
        $this->kata($this->tournament(['tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM]), ['student_id' => null, 'group_id' => 'team', 'students' => [$this->student->id]]);
        $expected = $this->medals();
        $web = $this->actingAs($this->org)->getJson('/api/panel/team/students/'.$this->student->id)->assertOk();
        $this->acceptMobileAgreements($this->coach);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'medal-secret'), 'expires_at' => now()->addHour()]);
        $mobile = $this->withToken('medal-secret')->getJson('/api/mobile/students/'.$this->student->id)->assertOk();
        foreach ($expected as $key => $counts) {
            $web->assertJsonPath('rating.'.$key.'.medals', $counts);
            $mobile->assertJsonPath('rating.'.$key.'.medals', $counts);
        }
    }

    public function test_foreign_history_remains_visible_but_only_authorized_tournaments_have_links(): void
    {
        $own = $this->tournament(['name' => 'Own']);
        $this->pool($own);
        $foreignOrg = $this->user('Organization');
        $foreignChamp = Championship::create(['name' => 'Other championship', 'organization_id' => $foreignOrg->id, 'banner' => 'other.jpg']);
        $foreign = $this->tournament(['name' => 'Foreign', 'organization_id' => $foreignOrg->id, 'championship_id' => $foreignChamp->id]);
        $this->pool($foreign, ['winner_id' => $this->opponent->id]);
        $mismatch = $this->tournament(['name' => 'Mismatched owner', 'championship_id' => $foreignChamp->id]);
        $this->pool($mismatch);
        foreach ([$own, $foreign, $mismatch] as $t) {
            StudentTournament::create(['student_id' => $this->student->id, 'tournament_id' => $t->id]);
        }
        foreach ([$this->org, $this->user('Secretary', ['organization_id' => $this->org->id])] as $viewer) {
            $response = $this->actingAs($viewer)->getJson('/api/panel/team/students/'.$this->student->id)->assertOk();
            $rows = collect($response->json('tournaments'))->keyBy('id');
            $this->assertTrue($rows[$own->id]['can_open']);
            $this->assertFalse($rows[$foreign->id]['can_open']);
            $this->assertFalse($rows[$mismatch->id]['can_open']);
            $this->assertFalse($response->json('fight_records.losses.0.tournament.can_open'));
            $this->assertSame('Foreign', $response->json('fight_records.losses.0.tournament.name'));
            $this->getJson('/api/panel/tournaments/'.$foreignChamp->id.'/items/'.$foreign->id)->assertForbidden();
            $this->getJson('/api/panel/tournaments/'.$foreignChamp->id.'/items/'.$mismatch->id)->assertForbidden();
            $this->getJson('/api/panel/tournaments/'.$this->championship->id.'/items/'.$own->id)->assertOk();
        }
    }

    public function test_visibility_service_preserves_coach_student_and_admin_rules_and_deleted_denials(): void
    {
        $own = $this->tournament();
        $other = $this->tournament();
        DB::table('tournament_treners')->insert(['tournament_id' => $own->id, 'trener_id' => $this->coach->id]);
        $visibility = app(PanelTournamentVisibility::class);
        $ids = [$own->id, $other->id];
        foreach ([$this->coach, $this->student] as $viewer) {
            $this->assertSame([$own->id], $visibility->linkableIds($viewer, $ids));
        }
        $this->assertSame($ids, $visibility->linkableIds($this->user('Admin'), $ids));
        $this->assertSame([], $visibility->linkableIds($this->user('Judge', ['organization_id' => $this->org->id]), $ids));
        $own->delete();
        $this->assertSame([], $visibility->linkableIds($this->coach, $ids));
        $this->championship->delete();
        $this->assertSame([], $visibility->linkableIds($this->org, $ids));
    }

    public function test_mobile_history_has_all_pages_and_deduplicates_tournaments(): void
    {
        $this->acceptMobileAgreements($this->coach);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'history-secret'), 'expires_at' => now()->addHour()]);
        $this->withToken('history-secret');
        foreach (range(1, 23) as $i) {
            $t = $this->tournament(['name' => 'History '.$i, 'date_finish' => today()]);
            StudentTournament::create(['student_id' => $this->student->id, 'tournament_id' => $t->id]);
            StudentTournament::create(['student_id' => $this->student->id, 'tournament_id' => $t->id]);
            foreach (range(1, 5) as $_) {
                $this->pool($t);
            }
        }
        $url = '/api/mobile/students/'.$this->student->id;
        $this->getJson($url)->assertOk()->assertJsonPath('rating.record.wins', 115);
        $first = $this->getJson($url.'/history?kind=tournaments')->assertOk()->assertJsonPath('meta.total', 23)->assertJsonCount(20, 'data')->json('data');
        $second = $this->getJson($url.'/history?kind=tournaments&page=2')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertCount(23, array_unique(array_column(array_merge($first, $second), 'id')));
        $ids = [];
        foreach (range(1, 6) as $page) {
            $rows = $this->getJson($url.'/history?kind=wins&page='.$page)->assertOk()->assertJsonPath('meta.total', 115)->json('data');
            $ids = array_merge($ids, array_column($rows, 'id'));
        }
        $this->assertCount(115, array_unique($ids));
        $this->getJson($url.'/categories?page=2')->assertOk()->assertJsonPath('meta.total', 23)->assertJsonCount(3, 'data');
        $this->getJson($url.'/history?kind=invalid')->assertUnprocessable();
        $this->getJson('/api/mobile/students?tournament_status=active')->assertOk()->assertJsonPath('meta.total', 1);
        $this->championship->delete();
        $this->getJson('/api/mobile/students?tournament_status=active')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/mobile/students?tournament_status=without_active')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_foreign_participant_has_only_safe_tournament_summary(): void
    {
        $this->acceptMobileAgreements($this->coach);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'public-secret'), 'expires_at' => now()->addHour()]);
        $this->withToken('public-secret');
        $t = $this->tournament();
        StudentTournament::create(['student_id' => $this->opponent->id, 'tournament_id' => $t->id]);
        $url = '/api/mobile/students/'.$this->opponent->id.'/public?tournament_id='.$t->id.'&championship_id='.$this->championship->id;
        $this->getJson($url)->assertForbidden();
        DB::table('tournament_treners')->insert(['tournament_id' => $t->id, 'trener_id' => $this->coach->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('public_only', true)->assertJsonPath('student.capabilities.edit', false)
            ->assertJsonMissingPath('student.email')->assertJsonMissingPath('student.birthday')->assertJsonMissingPath('documents');
        $this->getJson('/api/mobile/students/'.$this->opponent->id)->assertForbidden();
        $t->delete();
        $this->getJson($url)->assertForbidden();
    }

    public function test_medal_queries_are_aggregated_without_per_result_queries(): void
    {
        $t = $this->tournament();
        $this->pool($t);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->medals();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $initial = $count();
        foreach (range(1, 25) as $_) {
            $this->pool($t);
        }
        $this->assertSame($initial, $count());
    }
}
