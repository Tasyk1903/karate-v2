<?php

namespace Tests\Feature;

use App\Exports\TournamentListExport;
use App\Models\Championship;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\Pool;
use App\Models\Region;
use App\Models\Scale;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Tournaments\SpectatorFightPath;
use App\Services\Tournaments\SpectatorTatamiQueue;
use App\Services\Tournaments\TournamentDownloadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class MobileSpectatorTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $coach;

    private Championship $champ;

    private Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->travelTo(now()->setDate(2026, 9, 5)->setTime(12, 0, 0));
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $this->org = $this->user('Organization', ['can_edit_coaches' => true]);
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        $this->champ = Championship::create(['name' => 'Championship', 'banner' => 'banner.jpg', 'organization_id' => $this->org->id]);
        $this->tournament = Tournament::create(['name' => 'Tournament', 'organization_id' => $this->org->id, 'championship_id' => $this->champ->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM, 'age_from' => 0, 'age_to' => 100,
            'tatami' => 1, 'price' => 0, 'date_commission' => now()->addDay(), 'date' => now()->addDays(2), 'date_finish' => now()->addDays(3), 'address' => 'City']);
        DB::table('tournament_treners')->insert(['tournament_id' => $this->tournament->id, 'trener_id' => $this->coach->id]);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'tournament-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('tournament-test');
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create(array_replace(['first_name' => 'Alex', 'last_name' => 'Student', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')], $extra));
    }

    private function student(): User
    {
        return $this->user('Student', ['coach_id' => $this->coach->id, 'organization_id' => $this->org->id, 'birthday' => '2016-01-01', 'weight' => 30, 'rang' => '5 кю', 'gender' => 'm']);
    }

    private function template(string $type = 'personal', array $extra = []): TemplateStudentList
    {
        return TemplateStudentList::create(array_replace(['name' => $type, 'list_type' => $type === 'kumite' ? 'kumite' : 'kata', 'kata_type' => $type === 'kumite' ? null : $type, 'age_from' => 10, 'age_to' => 11, 'gender' => 'all', 'weight_from' => 20, 'weight_to' => 40, 'rang_from' => 10, 'rang_to' => 0, 'user_id' => $this->org->id], $extra));
    }

    private function list(string $type = 'personal', array $extra = []): ListTournament
    {
        return ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $this->template($type, $extra)->id]);
    }

    private function membership(User $student, ListTournament $list, ?string $group = null): TournamentStudentList
    {
        StudentTournament::firstOrCreate(['student_id' => $student->id, 'tournament_id' => $this->tournament->id], ['list_tournament_id' => $list->id]);

        return TournamentStudentList::create(['student_id' => $student->id, 'list_tournament_id' => $list->id, 'group_id' => $group]);
    }

    private function base(): string
    {
        return '/api/mobile/championships/'.$this->champ->id.'/tournaments/'.$this->tournament->id;
    }

    public function test_original_lists_and_members_are_paginated_and_read_only(): void
    {
        $fallback = $this->list('personal', ['name' => 'Unassigned']);
        $student = $this->student();
        $this->membership($student, $fallback);
        $deleted = $this->student();
        $this->membership($deleted, $fallback);
        $deleted->delete();
        $this->getJson($this->base().'/lists')->assertOk()->assertJsonPath('data.0.generated', false)->assertJsonPath('data.0.students_count', 1);
        $this->getJson($this->base().'/lists?generated=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->base().'/lists/'.$fallback->id.'/members')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.club', 'DOJO')->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.documents');
        for ($i = 0; $i < 21; $i++) {
            $this->list();
        }
        $this->getJson($this->base().'/lists?page=2')->assertOk()->assertJsonPath('meta.total', 22)->assertJsonCount(2, 'data');
        DB::table('tournament_treners')->delete();
        $this->getJson($this->base().'/lists/'.$fallback->id.'/members')->assertForbidden();
    }

    public function test_group_kata_returns_every_member_and_team_result(): void
    {
        $list = $this->list('group');
        $a = $this->student();
        $b = $this->student();
        $a->update(['first_name' => 'First']);
        $b->update(['first_name' => 'Second']);
        foreach ([$a, $b] as $student) {
            $this->membership($student, $list, 'team');
        }
        KataPool::create(['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'students' => [$a->id, $b->id], 'group_id' => 'team', 'round' => 'FINAL', 'participant_number' => 'A-1', 'total_score' => 24, 'winner_1' => true]);
        $response = $this->getJson($this->base().'/lists/'.$list->id.'/bracket')->assertOk()
            ->assertJsonCount(2, 'rounds.1.rows.0.members')->assertJsonPath('rounds.1.rows.0.winner_place', 1)
            ->assertJsonPath('rounds.1.rows.0.members.0.club', 'DOJO')
            ->assertJsonPath('rounds.1.rows.0.total_score', '24')
            ->assertJsonPath('rounds.1.rows.0.video_url', null)
            ->assertJsonPath('rounds.1.rows.0.can_update_final_video', false);
        $this->assertStringContainsString('First', $response->json('rounds.1.rows.0.name'));
        $this->assertStringContainsString('Second', $response->json('rounds.1.rows.0.name'));
        $this->getJson('/api/mobile/quick-fights')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.path.0.status', 'scored');
    }

    private function pool(ListTournament $list, array $data): Pool
    {
        return Pool::create($data + ['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'round' => 1, 'position_in_round' => 1]);
    }

    public function test_mobile_bracket_and_round_robin_podium_show_region_instead_of_club(): void
    {
        $region = Region::create(['name' => 'Participant region']);
        $scale = Scale::firstOrCreate(['slug' => Scale::REGION], ['name' => 'Regional']);
        $this->tournament->update(['tournament_type' => Tournament::KUMITE, 'scale_id' => $scale->id]);
        $list = $this->list('kumite');
        $a = $this->student();
        $b = $this->student();
        $a->update(['region_id' => $region->id]);
        $this->pool($list, ['student_id' => $a->id, 'opponent_id' => $b->id, 'type' => 'Round Robin', 'winner_id_1rd_robbin' => $a->id]);
        $response = $this->getJson($this->base().'/lists/'.$list->id.'/bracket')->assertOk();
        $expected = $region->name.' '.$a->coach_short;
        $response->assertJsonPath('rounds.0.pools.0.student.club', $expected)
            ->assertJsonPath('podium.0.participant.club', $expected);
        $this->assertStringNotContainsString('DOJO', $response->getContent());
    }

    public function test_fight_scores_avatars_and_real_depth_are_visible_without_editor_capabilities(): void
    {
        $this->tournament->update(['tournament_type' => Tournament::KUMITE]);
        $list = $this->list('kumite');
        $a = $this->student();
        $b = $this->student();
        $a->update(['avatar' => 'photos/student.jpg']);
        $this->pool($list, ['student_id' => $a->id, 'opponent_id' => $b->id, 'student_wazari_count' => 1, 'opponent_ippon' => true, 'winner_id' => $b->id]);
        $this->pool($list, ['round' => 2, 'type' => '1/2']);
        $this->pool($list, ['round' => 3, 'type' => 'final']);
        $this->pool($list, ['round' => 4, 'type' => '3rd']);
        $response = $this->getJson($this->base().'/lists/'.$list->id.'/bracket')->assertOk()
            ->assertJsonPath('rounds.0.title', '1/4')->assertJsonPath('rounds.1.title', '1/2')
            ->assertJsonPath('rounds.0.pools.0.student_wazari_count', 1)
            ->assertJsonPath('rounds.0.pools.0.opponent_ippon', true)
            ->assertJsonMissingPath('can_swap')->assertJsonMissingPath('revision');
        $this->assertStringEndsWith('/storage/photos/student.jpg', $response->json('rounds.0.pools.0.student.avatar'));
    }

    public function test_quick_data_own_scope_search_pagination_and_future_path(): void
    {
        $this->tournament->update(['tournament_type' => Tournament::KUMITE, 'fight_for_third_place' => true]);
        $list = $this->list('kumite');
        $list->update(['tatami' => 'A']);
        $a = $this->student();
        $a->update(['first_name' => 'Unique']);
        $b = $this->student();
        $other = $this->user('Coach');
        $b->update(['coach_id' => $other->id]);
        $this->membership($a, $list);
        $this->membership($b, $list);
        $semi = $this->pool($list, ['student_id' => $a->id, 'opponent_id' => $b->id, 'type' => '1/2', 'tatami_and_fight_number' => 'A-2']);
        $this->pool($list, ['round' => 2, 'type' => 'final', 'tatami_and_fight_number' => 'A-4']);
        $this->pool($list, ['round' => 3, 'type' => '3rd', 'tatami_and_fight_number' => 'A-3']);
        $this->getJson('/api/mobile/quick-fights?search=Unique')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.club', 'DOJO')
            ->assertJsonPath('data.0.path.0.status', 'upcoming')->assertJsonPath('data.0.path.1.status', 'possible')
            ->assertJsonPath('data.0.current.number', 'A-2');
        $semi->update(['winner_id' => $b->id]);
        $this->getJson('/api/mobile/quick-fights')->assertJsonPath('data.0.path.0.status', 'lost')->assertJsonCount(2, 'data.0.path');
        $semi->update(['absent_student' => true]);
        $this->getJson('/api/mobile/quick-fights')->assertJsonPath('data.0.path.0.status', 'absent')->assertJsonCount(1, 'data.0.path');
        for ($i = 0; $i < 22; $i++) {
            $this->membership($this->student(), $list);
        }
        $this->getJson('/api/mobile/quick-fights?page=2')->assertJsonPath('meta.total', 23)->assertJsonCount(3, 'data');
        $this->tournament->update(['date_finish' => now()->subDay()]);
        $this->getJson('/api/mobile/quick-fights')->assertJsonCount(0, 'data')->assertJsonCount(0, 'tournaments');
    }

    public function test_round_robin_path_keeps_all_bouts_after_a_loss(): void
    {
        $list = $this->list('kumite');
        $a = $this->student();
        $b = $this->student();
        $p1 = $this->pool($list, ['type' => 'Round Robin', 'student_id' => $a->id, 'opponent_id' => $b->id, 'winner_id' => $b->id]);
        $p2 = $this->pool($list, ['type' => 'Round Robin', 'student_id' => $a->id, 'opponent_id' => $b->id]);
        $path = app(SpectatorFightPath::class)->build(collect([$p1, $p2]), $a->id, 'A');
        $this->assertSame(['lost', 'upcoming'], array_column($path, 'status'));
    }

    public function test_real_list_exports_render_without_private_fields_or_formula_execution(): void
    {
        $list = $this->list('group');
        $a = $this->student();
        $a->update(['first_name' => 'Александр', 'last_name' => 'Длиннаяфамилия', 'club' => 'WRONG CLUB']);
        $this->membership($a, $list, 'team');
        $b = $this->student();
        $this->membership($b, $list, 'team');
        $pdf = $this->get($this->base().'/lists/export/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        if (getenv('SPECTATOR_PDF_PREVIEW')) {
            file_put_contents(storage_path('framework/testing/mobile-spectator.pdf'), $pdf->getContent());
        }
        $excel = $this->get($this->base().'/lists/export/excel')->assertOk();
        $file = $excel->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($file);
        $this->assertStringContainsString('ДЛИННАЯФАМИЛИЯ', $book->getActiveSheet()->getCell('B3')->getValue());
        $this->assertSame('DOJO', $book->getActiveSheet()->getCell('G3')->getValue());
        $book->disconnectWorksheets();
        $export = new TournamentListExport([]);
        $book = new Spreadsheet;
        $export->bindValue($book->getActiveSheet()->getCell('A1'), '=1+1');
        $this->assertSame('s', $book->getActiveSheet()->getCell('A1')->getDataType());
    }

    public function test_deep_stage_names_and_tatami_pending_order(): void
    {
        $path = app(SpectatorFightPath::class);
        $this->assertSame(['1/32', '1/16', '1/8', '1/4', '1/2'], array_map(fn ($r) => $path->stage($r, 6, null), range(1, 5)));
        $queue = app(SpectatorTatamiQueue::class);
        $list = $this->list('kumite');
        $a = $this->student();
        $b = $this->student();
        $zero = $this->pool($list, ['tatami_and_fight_number' => '0']);
        $waiting = $this->pool($list, ['tatami_and_fight_number' => 'A-1']);
        $ready = $this->pool($list, ['tatami_and_fight_number' => 'A-10', 'student_id' => $a->id, 'opponent_id' => $b->id]);
        $ready2 = $this->pool($list, ['tatami_and_fight_number' => 'A-2', 'student_id' => $a->id, 'opponent_id' => $b->id]);
        $this->assertSame([$ready2->id, $ready->id], $queue->pending(collect([$zero, $waiting, $ready, $ready2]), false)->pluck('id')->all());
        $this->assertSame([$waiting->id], $queue->pending(collect([$zero, $waiting]), false)->pluck('id')->all());
        $ready2->absent_student = true;
        $this->assertEmpty($queue->pending(collect([$ready2]), false));
    }

    public function test_direct_list_and_export_routes_do_not_cross_tournament_boundary(): void
    {
        $list = $this->list();
        $tournament = $this->tournament->replicate();
        $tournament->save();
        $list->update(['tournament_id' => $tournament->id]);
        $this->getJson($this->base().'/lists/'.$list->id.'/members')->assertNotFound();
        $this->getJson($this->base().'/lists/'.$list->id.'/bracket')->assertNotFound();
        $this->champ->delete();
        $this->getJson($this->base().'/lists/export/excel')->assertNotFound();
        $this->getJson('/api/mobile/quick-fights')->assertJsonCount(0, 'data');
    }

    public function test_list_exports_whitelist_access_and_audit(): void
    {
        $downloads = \Mockery::mock(TournamentDownloadService::class);
        $downloads->shouldReceive('download')->once()->withArgs(fn ($t, $format) => $t->id === $this->tournament->id && $format === 'lists-pdf')->andReturn(response('%PDF', 200));
        $this->app->instance(TournamentDownloadService::class, $downloads);
        $this->get($this->base().'/lists/export/pdf')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.lists.exported', 'causer_id' => $this->coach->id]);
        $this->get($this->base().'/lists/export/kumite-protocols')->assertNotFound();
        DB::table('tournament_treners')->delete();
        $this->getJson($this->base().'/lists/export/excel')->assertForbidden();
        $this->getJson('/api/mobile/quick-fights')->assertJsonCount(0, 'data');
    }
}
