<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\PanelTask;
use App\Models\Pool;
use App\Models\Region;
use App\Models\Scale;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Exports\KataPdf;
use App\Services\Tournaments\TournamentDownloadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PanelExportTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $coach;

    private Championship $champ;

    private Tournament $tournament;

    private array $pdfData = [];

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Queue::fake();
        Storage::fake('protected');
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(12, 0));
        foreach (['Organization', 'Secretary', 'Coach', 'Student', 'Judge'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization');
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'Международный клуб боевых искусств Северная столица']);
        $this->champ = Championship::create(['name' => 'Чемпионат Karate Rating', 'banner' => 'none.jpg', 'organization_id' => $this->org->id]);
        $this->tournament = Tournament::create(['name' => 'Кумитэ 10–11 лет', 'organization_id' => $this->org->id, 'championship_id' => $this->champ->id, 'tournament_type' => Tournament::KUMITE,
            'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'date_commission' => now()->addDay(), 'date' => now()->addDays(2), 'date_finish' => now()->addDays(3), 'fight_for_third_place' => true, 'address' => 'Warsaw', 'chief_judge' => 'Главный Судья', 'chief_secretary' => 'Главный Секретарь']);
        $this->actingAs($this->org);
        View::composer(['pdf.bracket', 'pdf.report-bracket', 'pdf.kata-table-pdf', 'pdf.kata-report', 'pdf.report-pdf', 'pdf.official-results', 'pdf.tournament-certificate'], function ($view) {
            $data = $view->getData();
            if (isset($data['sortedLists']) && isset($this->pdfData[$view->name()]['sortedLists'])) {
                $data['sortedLists'] = $this->pdfData[$view->name()]['sortedLists']->merge($data['sortedLists'])->unique('id')->values();
            }
            $this->pdfData[$view->name()] = $data;
        });
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role = 'Student', array $extra = []): User
    {
        return User::create($extra + ['first_name' => 'Александр', 'last_name' => 'Константинопольский', 'email' => Str::uuid().'@example.test', 'password' => 'password',
            'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function student(array $extra = []): User
    {
        return $this->user('Student', $extra + ['first_name' => 'Александр '.User::count(), 'coach_id' => $this->coach->id, 'organization_id' => $this->org->id, 'birthday' => '2016-01-01', 'weight' => 30, 'rang' => '5 кю', 'gender' => 'm', 'club' => 'WRONG STUDENT CLUB']);
    }

    private function list(string $type = 'kumite', string $name = 'Категория'): ListTournament
    {
        $template = TemplateStudentList::create(['name' => $name, 'user_id' => $this->org->id, 'list_type' => $type === 'kumite' ? 'kumite' : 'kata', 'kata_type' => $type === 'kumite' ? null : $type]);

        return ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $template->id]);
    }

    private function base(): string
    {
        return "/api/panel/tournaments/{$this->champ->id}/items/{$this->tournament->id}";
    }

    private function sheet(PanelTask $task)
    {
        $this->assertSame('ready', $task->status);

        return IOFactory::load(Storage::disk('protected')->path($task->path))->getActiveSheet();
    }

    private function bracket(int $n, bool $third = true): ListTournament
    {
        $list = $this->list('kumite', "Кумитэ {$n} участников");
        $students = collect(range(1, $n))->map(fn ($i) => $this->student(['first_name' => "Александр {$i}"]));
        $size = 2 ** (int) ceil(log($n, 2));
        $round = 1;
        $current = $students->all();
        while ($size > 1) {
            $next = [];
            for ($i = 0; $i < $size / 2; $i++) {
                $a = $current[$i * 2] ?? null;
                $b = $current[$i * 2 + 1] ?? null;
                Pool::create(['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'student_id' => $a?->id, 'opponent_id' => $b?->id, 'round' => $round,
                    'position_in_round' => $i + 1, 'type' => $size === 2 ? 'final' : 'regular', 'winner_id' => $a?->id, 'tatami_and_fight_number' => "A-{$round}-{$i}", 'student_wazari_count' => 1]);
                $next[] = $a;
            }
            $current = $next;
            $size /= 2;
            $round++;
        }
        if ($third && $n >= 4) {
            Pool::create(['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'student_id' => $students[1]->id, 'opponent_id' => $students[3]->id,
                'round' => $round - 1, 'position_in_round' => 2, 'type' => '3rd', 'winner_id' => $students[1]->id]);
        }
        foreach ($students as $student) {
            StudentTournament::create(['tournament_id' => $this->tournament->id, 'student_id' => $student->id, 'list_tournament_id' => $list->id]);
            TournamentStudentList::create(['list_tournament_id' => $list->id, 'student_id' => $student->id]);
        }

        return $list;
    }

    private function kata(string $type): ListTournament
    {
        $this->tournament->update(['tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM, 'name' => 'Ката балльная система']);
        $list = $this->list($type, $type === 'group' ? 'Групповая ката 10–11 лет' : 'Личная ката 10–11 лет');
        for ($i = 1; $i <= 4; $i++) {
            $members = collect(range(1, $type === 'group' ? 3 : 1))->map(fn () => $this->student());
            foreach ($members as $member) {
                StudentTournament::create(['student_id' => $member->id, 'tournament_id' => $this->tournament->id, 'list_tournament_id' => $list->id]);
                TournamentStudentList::create(['student_id' => $member->id, 'list_tournament_id' => $list->id, 'group_id' => $type === 'group' ? "group-{$i}" : null]);
            }
            foreach (['PRELIMINARY STAGE', 'FINAL'] as $round) {
                KataPool::create([
                    'tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'student_id' => $type === 'personal' ? $members[0]->id : null,
                    'students' => $type === 'group' ? $members->pluck('id')->all() : null, 'group_id' => $type === 'group' ? "group-{$i}" : null,
                    'round' => $round, 'participant_number' => $i, 'referee_score' => 8, 'judge1_score' => 8.1, 'judge2_score' => 8.2, 'judge3_score' => 8.3, 'judge4_score' => 8.4,
                    'total_score' => 24.6, 'min_score' => 8, 'max_score' => 8.4, 'winner_1' => $round === 'FINAL' && $i === 1, 'winner_2' => $round === 'FINAL' && $i === 2, 'winner_3' => $round === 'FINAL' && $i === 3]);
            }
        }

        return $list;
    }

    public function test_queue_deduplicates_authorizes_and_downloads_private_file(): void
    {
        $this->bracket(4);
        $url = $this->base().'/downloads/brackets?locale=en';
        $first = $this->getJson($url)->assertStatus(202)->json('task.id');
        $this->assertSame($first, $this->getJson($url)->assertStatus(202)->json('task.id'));
        $task = $this->runPanelTask($first);
        $this->assertSame('ready', $task->status);
        $this->assertSame('en', $task->locale);
        $this->assertStringStartsWith('%PDF', Storage::disk('protected')->get($task->path));
        $this->get('/api/panel/tasks/'.$first.'/file')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('activity_log', ['event' => 'panel.task.downloaded', 'causer_id' => $this->org->id]);
        $this->actingAs($this->user('Organization'))->getJson('/api/panel/tasks/'.$first)->assertForbidden();
        $this->getJson('/api/panel/tasks/'.$first.'/file')->assertForbidden();
        $this->actingAs($this->org);
        $task->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/panel/tasks/'.$first.'/file')->assertNotFound();
        $this->artisan('panel:clean-tasks')->assertSuccessful();
        Storage::disk('protected')->assertMissing($task->path);
    }

    public function test_revocation_and_interrupted_files_fail_closed(): void
    {
        $id = $this->getJson('/api/panel/team/export?section=judges')->assertStatus(202)->json('task.id');
        $this->org->update(['role_id' => DB::table('roles')->where('name', 'Secretary')->value('id')]);
        $this->assertSame('failed', $this->runPanelTask($id)->status);
        $task = PanelTask::find($id);
        $task->update(['status' => 'processing', 'updated_at' => now()->subMinutes(20)]);
        Storage::disk('protected')->put('panel-tasks/'.$id.'.pdf', 'partial');
        $this->artisan('panel:clean-tasks')->assertSuccessful();
        Storage::disk('protected')->assertMissing('panel-tasks/'.$id.'.pdf');
    }

    public function test_team_export_includes_more_than_5000_filtered_students_and_literal_names(): void
    {
        $role = DB::table('roles')->where('name', 'Student')->value('id');
        foreach (array_chunk(range(1, 5001), 400) as $chunk) {
            DB::table('users')->insert(array_map(fn ($i) => [
                'first_name' => '=1+1', 'last_name' => 'Export '.$i, 'email' => "export-{$i}@example.test", 'password' => 'x', 'role_id' => $role, 'coach_id' => $this->coach->id, 'organization_id' => $this->org->id, 'created_at' => now(), 'updated_at' => now()], $chunk));
        }
        $this->student(['last_name' => 'Excluded']);
        $id = $this->getJson('/api/panel/team/export?section=students&search=Export&locale=en')->assertStatus(202)->json('task.id');
        $sheet = $this->sheet($this->runPanelTask($id));
        $this->assertSame(5002, $sheet->getHighestRow());
        $this->assertStringContainsString('Full name', implode(' ', $sheet->rangeToArray('A1:J1')[0]));
        $this->assertStringContainsString('=1+1', implode(' ', $sheet->rangeToArray('A2:J2')[0]));
    }

    public function test_multiple_coaches_external_team_and_duplicate_membership_export_once(): void
    {
        $a = $this->student(['first_name' => 'Alpha']);
        $external = $this->user('Coach', ['organization_id' => $this->org->id, 'is_external' => true, 'club' => 'External']);
        $b = $this->student(['first_name' => 'Beta', 'coach_id' => $external->id]);
        $c = $this->student(['first_name' => 'Excluded', 'coach_id' => $this->user('Coach', ['organization_id' => $this->org->id])->id]);
        $list = $this->list();
        foreach ([$a, $b, $c] as $student) {
            StudentTournament::create(['student_id' => $student->id, 'tournament_id' => $this->tournament->id, 'list_tournament_id' => $list->id]);
        }
        $second = $this->tournament->replicate();
        $second->tournament_type = Tournament::KATA;
        $second->tournament_type_kata = Tournament::POINT_SYSTEM;
        $second->save();
        StudentTournament::create(['student_id' => $a->id, 'tournament_id' => $second->id]);
        StudentTournament::create(['student_id' => $a->id, 'tournament_id' => $second->id]);
        $url = "/api/panel/tournaments/{$this->champ->id}/export?trainer_ids_csv={$this->coach->id},{$external->id}&locale=en";
        $sheet = $this->sheet($this->runPanelTask($this->getJson($url)->assertStatus(202)->json('task.id')));
        $rows = collect($sheet->toArray());
        $this->assertCount(1, $rows->where('0', 'Alpha'));
        $this->assertCount(1, $rows->where('0', 'Beta'));
        $this->assertCount(0, $rows->where('0', 'Excluded'));
        $row = $rows->firstWhere('0', 'Alpha');
        $this->assertEquals(10, $row[2]);
        $this->assertEquals(2, $row[3]);
        $this->assertStringContainsString('Kumite', $row[4]);
        $this->assertStringContainsString('Kata', $row[4]);
        $this->getJson("/api/panel/tournaments/{$this->champ->id}/export?trainer_ids_csv=999999")->assertUnprocessable();
    }

    public function test_kata_batch_single_audit_and_constant_query_count(): void
    {
        $personal = $this->kata('personal');
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $data = app(KataPdf::class)->sheets($this->tournament);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$data, $n];
        };
        [$data,$before] = $count();
        $this->kata('group');
        [$data,$after] = $count();
        $this->assertLessThanOrEqual($before + 2, $after);
        $this->assertCount(2, $data);
        $html = view('pdf.kata-table-pdf', ['tournament' => $this->tournament, 'sheets' => $data, 'logoSrc' => null, 'protocol' => false])->render();
        $this->assertStringContainsString('Групповая ката', $html);
        $this->assertStringNotContainsString('WRONG STUDENT CLUB', $html);
        $id = $this->getJson($this->base().'/kata/'.$personal->id.'/pdf')->assertStatus(202)->json('task.id');
        $this->assertSame('ready', $this->runPanelTask($id)->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.kata_table.downloaded', 'causer_id' => $this->org->id]);
    }

    public function test_pdf_matrix_renders_real_files(): void
    {
        foreach ([2, 3, 4, 8, 16, 32] as $n) {
            $this->bracket($n);
        }
        $rr = $this->list('kumite', 'Round Robin · 3 участника');
        $rrPeople = collect(range(1, 3))->map(fn () => $this->student());
        foreach ([[0, 1], [0, 2], [1, 2]] as $i => [$a,$b]) {
            Pool::create(['tournament_id' => $this->tournament->id, 'list_id' => $rr->id,
                'student_id' => $rrPeople[$a]->id, 'opponent_id' => $rrPeople[$b]->id, 'winner_id' => $rrPeople[$a]->id, 'round' => 1, 'position_in_round' => $i + 1, 'type' => 'Round Robin',
                'winner_id_1rd_robbin' => $rrPeople[0]->id, 'winner_id_2rd_robbin' => $rrPeople[1]->id, 'winner_id_3rd_robbin' => $rrPeople[2]->id]);
        }
        $service = app(TournamentDownloadService::class);
        foreach (['brackets', 'kumite-protocols', 'results', 'certificate', 'lists-pdf'] as $type) {
            $this->savePdf($type, $service->download($this->tournament, $type)->getContent());
        }
        $this->kata('personal');
        $this->kata('group');
        foreach (['kata-tables', 'kata-protocols', 'results'] as $type) {
            $this->savePdf('kata-'.$type, $service->download($this->tournament, $type)->getContent());
        }
        app()->setLocale('en');
        foreach (['kata-tables', 'kata-protocols', 'certificate', 'lists-pdf'] as $type) {
            $this->savePdf('en-'.$type, $service->download($this->tournament, $type)->getContent());
        }
    }

    public function test_attach_options_are_paged_scoped_and_detail_is_selective(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $this->user('Coach', ['organization_id' => $this->org->id, 'last_name' => 'Coach '.sprintf('%02d', $i)]);
        }
        $outsider = $this->user('Coach', ['organization_id' => $this->user('Organization')->id, 'last_name' => 'Outside']);
        $this->getJson($this->base().'/attach-options/coaches?page=1')->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson($this->base().'/attach-options/coaches?page=2')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson($this->base().'/attach-options/coaches?search=Outside')->assertOk()->assertJsonCount(0, 'data');
        for ($i = 0; $i < 35; $i++) {
            $this->list('kumite', 'Eligible '.$i)->delete();
        }
        $this->list('personal', 'Wrong discipline')->delete();
        $this->getJson($this->base().'/attach-options/lists?page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson($this->base().'/attach-options/lists?search=Wrong')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->base().'?parts=coaches&metadata=0')->assertOk()->assertJsonMissingPath('detail.students')->assertJsonMissingPath('detail.lists')->assertJsonMissingPath('detail.options')->assertJsonMissingPath('create_options');
        $this->actingAs($outsider)->getJson($this->base().'/attach-options/coaches')->assertForbidden();
    }

    public function test_queued_generation_rejects_changed_results(): void
    {
        $list = $this->bracket(4);
        $queued = $this->postJson($this->base().'/brackets/generate')->assertStatus(202)->json('task.id');
        $pool = Pool::where('list_id', $list->id)->first();
        $pool->update(['student_wazari_count' => 2]);
        $before = Pool::where('list_id', $list->id)->get()->toArray();
        $this->assertSame('failed', $this->runPanelTask($queued)->status);
        $this->assertSame($before, Pool::where('list_id', $list->id)->get()->toArray());
    }

    public function test_history_has_next_page_and_english_validation(): void
    {
        $student = $this->student();
        $scale = Scale::create(['name' => 'City', 'slug' => Scale::CITY]);
        $this->tournament->update(['scale_id' => $scale->id]);
        for ($i = 0; $i < 25; $i++) {
            $t = $this->tournament->replicate();
            $t->save();
            StudentTournament::create(['tournament_id' => $t->id, 'student_id' => $student->id]);
        }
        $url = '/api/panel/team/students/'.$student->id.'/history';
        $this->getJson($url.'?kind=tournaments')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 25);
        $this->getJson($url.'?kind=tournaments&page=2')->assertOk()->assertJsonCount(5, 'data');
        $response = $this->getJson($url.'?kind=bad&locale=en')->assertUnprocessable();
        $this->assertStringContainsString('invalid', $response->json('message'));
        $this->actingAs($this->user('Organization'))->getJson($url.'?kind=tournaments')->assertForbidden();
    }

    public function test_trainer_students_use_bounded_queries_and_localized_exports(): void
    {
        $student = $this->student(['rang' => '2 дан']);
        $url = '/api/panel/team/trainers/'.$this->coach->id;
        $count = function () use ($url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->getJson($url.'?per_page=50&locale=en')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame($this->coach->club, $response->json('students.data.0.club'));

            return $n;
        };
        $before = $count();
        for ($i = 0; $i < 20; $i++) {
            $this->student();
        }
        $this->assertLessThanOrEqual($before + 1, $count());
        $id = $this->getJson($url.'/students/export?format=xlsx&locale=en')->assertStatus(202)->json('task.id');
        $sheet = $this->sheet($this->runPanelTask($id));
        $this->assertSame(22, $sheet->getHighestRow());
        $this->assertEquals(10, $sheet->getCell('C2')->getValue());
        $this->assertSame('2 dan', $sheet->getCell('E2')->getValue());
        $id = $this->getJson($url.'/students/export?format=pdf&locale=en')->assertStatus(202)->json('task.id');
        $this->assertSame('ready', $this->runPanelTask($id)->status);
    }

    public function test_regional_bracket_pdf_and_protocol_replace_clubs_in_regular_and_round_robin_draws(): void
    {
        $region = Region::create(['name' => 'Москва и Московская область']);
        $scale = Scale::firstOrCreate(['slug' => Scale::REGION], ['name' => 'Regional']);
        $this->tournament->update(['scale_id' => $scale->id]);
        $list = $this->bracket(4);
        User::where('coach_id', $this->coach->id)->update(['region_id' => $region->id]);
        $a = $this->student(['region_id' => $region->id]);
        $b = $this->student();
        $rr = $this->list('kumite', 'Round Robin');
        Pool::create(['tournament_id' => $this->tournament->id, 'list_id' => $rr->id, 'student_id' => $a->id, 'opponent_id' => $b->id,
            'round' => 1, 'position_in_round' => 1, 'type' => 'Round Robin', 'winner_id_1rd_robbin' => $a->id]);
        foreach (['brackets' => 'pdf.bracket', 'kumite-protocols' => 'pdf.report-bracket'] as $type => $view) {
            $response = app(TournamentDownloadService::class)->download($this->tournament->fresh(), $type);
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $html = view($view, $this->pdfData[$view])->render();
            $this->assertStringContainsString($region->name, $html);
            $this->assertStringContainsString(__('exports.region_not_specified'), $html);
            $this->assertStringNotContainsString($this->coach->club, $html);
            if (getenv('SAVE_EXPORT_QA')) {
                $dir = storage_path('framework/testing/export-qa');
                if (! is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($dir.'/regional-'.$type.'.pdf', $response->getContent());
            }
        }
    }

    private function savePdf(string $name, string $bytes): void
    {
        $this->assertStringStartsWith('%PDF', $bytes);
        if (getenv('SAVE_EXPORT_QA')) {
            $dir = storage_path('framework/testing/export-qa');
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            } file_put_contents($dir.'/'.$name.'.pdf', $bytes);
            $view = match (str_starts_with($name, 'en-') ? substr($name, 3) : $name) {
                'brackets' => 'bracket','kumite-protocols' => 'report-bracket','results','kata-results' => 'official-results','certificate' => 'tournament-certificate','lists-pdf' => 'report-pdf','kata-kata-tables','kata-tables' => 'kata-table-pdf','kata-kata-protocols','kata-protocols' => 'kata-report'
            };
            if (! str_starts_with($name, 'en-') && is_file($dir.'/legacy/'.$view.'.blade.php')) {
                $data = $this->pdfData['pdf.'.$view] + ['logoPath' => null, 'logoSrc' => null];
                if (isset($data['sheets'])) {
                    $data['listTournaments'] = $data['sheets']->map(function ($sheet) {
                        return $sheet['listTournament']->setRelation('kataPools', $sheet['kataPools']);
                    });
                }
                if (isset($data['rows'])) {
                    // The legacy results formatter accepts individual medals only.
                    $data['pages'] = collect($data['rows'])->filter(fn ($row) => ! ($row['gold'] instanceof Collection))->chunk(14)->map(fn ($rows) => ['left' => $rows->take(7)->all(), 'right' => $rows->slice(7)->all()])->all();
                }
                $source = file_get_contents($dir.'/legacy/'.$view.'.blade.php');
                $icon = file_get_contents($dir.'/legacy/trophy.svg');
                $source = preg_replace_callback('/<x-bi-trophy([^>]*)\/>/', fn ($m) => str_replace('<svg ', '<svg '.$m[1].' ', $icon), $source);
                file_put_contents($dir.'/old-'.$name.'.html', Blade::render($source, $data));
            }
        }
    }
}
