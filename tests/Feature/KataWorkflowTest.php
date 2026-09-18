<?php

namespace Tests\Feature;

use App\Jobs\DeleteUnusedKataVideo;
use App\Models\Championship;
use App\Models\EducationKlassCategory;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\Scale;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\RatingService;
use App\Services\Tournaments\Kata\KataFinalVideoService;
use App\Services\Tournaments\Kata\KataScores;
use App\Services\Tournaments\Kata\KataScoreService;
use App\Services\Tournaments\Kata\KataState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class KataWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private Championship $champ;

    private Tournament $tournament;

    private ListTournament $list;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        foreach (['Organization', 'Secretary', 'Judge', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization');
        $this->champ = Championship::create(['name' => 'Champ', 'banner' => 'test.jpg', 'organization_id' => $this->org->id]);
        $this->tournament = Tournament::create(['name' => 'Kata', 'organization_id' => $this->org->id, 'championship_id' => $this->champ->id, 'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM, 'is_online_kata' => true, 'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'date_commission' => now(), 'date' => now(), 'date_finish' => now()->addDay(), 'address' => 'City']);
        $template = TemplateStudentList::create(['name' => 'Kata 10-11', 'user_id' => $this->org->id, 'list_type' => 'kata', 'kata_type' => 'personal']);
        $this->list = ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $template->id, 'finalists_count' => 4]);
        Storage::fake('protected');
        Storage::fake('public');
        $this->actingAs($this->org);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role = 'Student', array $extra = []): User
    {
        return User::create($extra + ['first_name' => 'Alex', 'last_name' => 'Example', 'email' => Str::uuid().'@test.example', 'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function base(): string
    {
        return '/api/panel/tournaments/'.$this->champ->id.'/items/'.$this->tournament->id;
    }

    private function pools()
    {
        return KataPool::where('list_id', $this->list->id)->orderBy('id')->get();
    }

    private function revision(): string
    {
        return KataState::revision($this->list->fresh(), $this->pools());
    }

    private function pool(array $data = []): KataPool
    {
        return KataPool::create($data + ['tournament_id' => $this->tournament->id, 'list_id' => $this->list->id, 'student_id' => $this->user()->id, 'round' => 'PRELIMINARY STAGE']);
    }

    private function scored(float $value, array $extra = []): KataPool
    {
        $pool = $this->pool($extra + array_fill_keys(KataScores::FIELDS, $value));
        KataScores::calculate($pool);
        $pool->save();

        return $pool;
    }

    private function score(KataPool $pool, string $field, mixed $value, array $extra = [])
    {
        return $this->postJson($this->base().'/kata-pools/'.$pool->id.'/score', $extra + ['field' => $field, 'value' => $value, 'original_value' => $pool->fresh()->getRawOriginal($field), 'revision' => $this->revision()]);
    }

    private function action(string $action, array $data = [])
    {
        return $this->postJson($this->base().'/kata/'.$this->list->id.'/'.$action, $data + ['revision' => $this->revision()]);
    }

    private function pre()
    {
        return collect([9, 8, 7, 6, 5])->map(fn ($v) => $this->scored($v));
    }

    private function final()
    {
        $pre = $this->pre();
        $this->action('final')->assertOk();

        return [$pre, $this->pools()->where('round', 'FINAL')->values()];
    }

    private function awarded()
    {
        [$pre, $final] = $this->final();
        foreach ($final as $index => $p) {
            $p->fill(array_fill_keys(KataScores::FIELDS, 9 - $index));
            KataScores::calculate($p);
            $p->save();
        }
        $this->action('winners')->assertOk();

        return [$pre, $final];
    }

    public function test_scores_accept_both_decimal_separators_zero_ten_and_clear_derived_values(): void
    {
        $p = $this->pool();
        foreach (array_combine(KataScores::FIELDS, ['0', '10', '8,1', '8.2', '8.3']) as $field => $value) {
            $this->score($p, $field, $value)->assertOk();
        }
        $this->assertEquals(24.6, $p->refresh()->total_score);
        $this->assertEquals(0, $p->min_score);
        $this->assertEquals(10, $p->max_score);
        $this->score($p, 'referee_score', '')->assertOk()->assertJsonPath('pools.pre.0.total_score', null);
        foreach (['referee_score', ...KataScores::DERIVED] as $field) {
            $this->assertNull($p->refresh()->getRawOriginal($field));
        }
        $this->score($p, 'referee_score', '8,0')->assertOk();
        $this->assertEquals(24.6, $p->refresh()->total_score);
    }

    public function test_invalid_legacy_scores_can_be_corrected_and_stale_empty_totals_cleared(): void
    {
        $pool = $this->scored(8);
        $pool->update(['referee_score' => '8.11']);
        $this->getJson($this->base().'/kata/'.$this->list->id)->assertOk()->assertJsonPath('pools.pre.0.referee_score', '8.11');
        $this->score($pool, 'referee_score', '8.1')->assertOk();
        $pool->update(['referee_score' => '8.00']);
        $this->score($pool, 'referee_score', '8.0')->assertOk();
        $this->assertSame('8.0', $pool->fresh()->getRawOriginal('referee_score'));
        $pool->update(['referee_score' => null, 'total_score' => 24]);
        $this->score($pool, 'referee_score', null)->assertOk();
        $this->assertNull($pool->fresh()->total_score);
    }

    public function test_equal_min_and_max_remove_exactly_two_scores(): void
    {
        $p = $this->scored(8);
        $this->score($p, 'referee_score', '8.1')->assertOk();
        $this->assertEquals(24, $p->refresh()->total_score);
        $this->score($p, 'referee_score', '8')->assertOk();
        $this->assertEquals(24, $p->refresh()->total_score);
        $this->score($p, 'referee_score', null)->assertOk();
        $this->assertNull($p->refresh()->total_score);
    }

    public function test_malformed_and_out_of_range_scores_do_not_write(): void
    {
        $p = $this->pool();
        foreach (['abc', '8abc', 'NaN', 'INF', '1e1', '-0.1', '-1', '10.1', '11', '8.11', '8,2,1', [], true] as $value) {
            $this->score($p, 'referee_score', $value)->assertUnprocessable();
            $this->assertNull($p->fresh()->referee_score);
        }
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_two_stale_models_edit_different_columns_without_lost_updates(): void
    {
        $p = $this->scored(7);
        $first = $p->fresh();
        $second = $p->fresh();
        app(KataScoreService::class)->update($this->org, $first, 'judge1_score', '8.1', '7.0', false, null);
        app(KataScoreService::class)->update($this->org, $second, 'judge2_score', '8.2', '7.0', false, null);
        $this->assertEquals(8.1, $p->refresh()->judge1_score);
        $this->assertEquals(8.2, $p->judge2_score);
        $this->assertEquals(22.1, $p->total_score);
        $this->score($p, 'judge1_score', '9', ['original_value' => '7.0'])->assertConflict();
        $this->assertEquals(8.1, $p->refresh()->judge1_score);
    }

    public function test_preliminary_change_requires_confirmation_then_removes_final_awards_and_ranks(): void
    {
        [$pre, $final] = $this->awarded();
        $snapshot = KataState::snapshot($this->list, $this->pools());
        $this->score($pre[0], 'referee_score', '0')->assertConflict()->assertJsonPath('code', 'kata_confirmation_required');
        $this->assertSame($snapshot, KataState::snapshot($this->list, $this->pools()));
        $this->score($pre[0], 'referee_score', '0', ['confirmed' => true])->assertOk()->assertJsonCount(0, 'pools.final');
        $this->assertSame(0, $this->pools()->whereNotNull('rank')->count());
        $this->assertDatabaseMissing('kata_pools', ['id' => $final[0]->id]);
        $log = json_decode(DB::table('activity_log')->orderByDesc('id')->value('properties'), true);
        $this->assertCount(9, $log['old']['pools']);
        $this->assertCount(5, $log['new']['pools']);
        $this->assertTrue($log['old']['pools'][$final[0]->id]['winner_1']);
    }

    public function test_final_change_clears_podium_but_preserves_finalists_and_other_scores(): void
    {
        [, $final] = $this->awarded();
        $this->score($final[0], 'judge4_score', '', ['confirmed' => true])->assertOk()->assertJsonCount(4, 'pools.final');
        foreach ($this->pools()->where('round', 'FINAL') as $p) {
            $this->assertNull($p->rank);
            $this->assertFalse($p->winner_1 || $p->winner_2 || $p->winner_3);
        }
        $this->assertEquals(8, $final[1]->fresh()->judge4_score);
        $this->assertNull($final[0]->fresh()->total_score);
        $this->action('winners')->assertUnprocessable();
        $this->score($final[0], 'judge4_score', '9')->assertOk();
        $this->action('winners')->assertOk();
    }

    public function test_count_and_regeneration_confirm_and_reject_stale_revision(): void
    {
        [, $final] = $this->awarded();
        $revision = $this->revision();
        $this->action('finalists-count', ['count' => 5])->assertConflict();
        $this->action('final')->assertConflict();
        $this->action('winners')->assertConflict();
        $this->action('finalists-count', ['count' => 5, 'confirmed' => true])->assertOk()->assertJsonCount(0, 'pools.final');
        $this->action('final', ['revision' => $revision, 'confirmed' => true])->assertConflict();
        $this->action('final')->assertOk()->assertJsonCount(5, 'pools.final');
        $this->assertDatabaseMissing('kata_pools', ['id' => $final[0]->id]);
    }

    public function test_final_cutoff_tie_is_atomic_and_min_max_break_ties(): void
    {
        $pre = $this->pre();
        $pre[4]->fill(array_fill_keys(KataScores::FIELDS, 6));
        KataScores::calculate($pre[4]);
        $pre[4]->save();
        $this->action('final')->assertUnprocessable();
        $this->assertCount(0, $this->pools()->whereNotNull('rank'));
        $this->score($pre[3], 'judge4_score', '6.1')->assertOk();
        $this->action('final')->assertOk();
        $this->assertTrue($this->pools()->where('round', 'FINAL')->contains('student_id', $pre[3]->student_id));
        $this->assertFalse($this->pools()->where('round', 'FINAL')->contains('student_id', $pre[4]->student_id));
    }

    public function test_final_tie_uses_preliminary_group_identity_and_unresolved_podium_is_rejected(): void
    {
        $members = [$this->user()->id, $this->user()->id, $this->user()->id];
        foreach ([9, 8, 7, 6] as $v) {
            $this->scored($v, ['student_id' => null, 'group_id' => (string) Str::uuid(), 'students' => $members]);
        }
        $this->action('final')->assertOk();
        foreach ($this->pools()->where('round', 'FINAL') as $p) {
            $p->fill(array_fill_keys(KataScores::FIELDS, 8));
            KataScores::calculate($p);
            $p->save();
        }
        $this->action('winners')->assertOk();
        $gold = $this->pools()->firstWhere('winner_1', true);
        $preGold = $this->pools()->where('round', 'PRELIMINARY STAGE')->firstWhere('group_id', $gold->group_id);
        $this->assertEquals(9, $preGold->judge1_score);
        foreach ($this->pools()->where('round', 'PRELIMINARY STAGE') as $p) {
            $p->fill(array_fill_keys(KataScores::FIELDS, 8));
            KataScores::calculate($p);
            $p->save();
        }
        $before = KataState::snapshot($this->list, $this->pools());
        $this->action('winners', ['confirmed' => true])->assertUnprocessable();
        $this->assertSame($before, KataState::snapshot($this->list, $this->pools()));
    }

    public function test_score_rights_judge_privacy_scope_and_tournament_type(): void
    {
        $p = $this->scored(8);
        $judge = $this->user('Judge', ['organization_id' => $this->org->id, 'judge_position' => 'judge1_score']);
        $this->actingAs($judge);
        $this->score($p, 'referee_score', '7')->assertForbidden();
        $this->score($p, 'judge1_score', '7')->assertOk()->assertJsonPath('pools.pre.0.referee_score', null)->assertJsonPath('pools.pre.0.total_score', null)->assertJsonPath('pools.pre.0.judge1_score', '7.0');
        $this->action('final')->assertForbidden();
        foreach (['Organization', 'Judge', 'Coach'] as $role) {
            $this->actingAs($this->user($role));
            $this->score($p, 'referee_score', '6')->assertForbidden();
        }
        $this->actingAs($this->user('Secretary', ['organization_id' => $this->org->id]));
        $this->score($p, 'referee_score', '6')->assertOk();
        $this->tournament->update(['tournament_type' => Tournament::KUMITE]);
        $this->score($p, 'referee_score', '5')->assertNotFound();
        $this->tournament->update(['tournament_type' => Tournament::KATA, 'date_finish' => now()->subDay()]);
        $this->score($p, 'referee_score', '5')->assertForbidden();
    }

    public function test_destructive_confirmation_cannot_apply_to_a_changed_table(): void
    {
        [$pre, $final] = $this->final();
        $revision = $this->revision();
        $this->score($pre[0], 'referee_score', '8', ['revision' => $revision])->assertStatus(409);
        $this->score($final[0], 'judge1_score', '7')->assertOk();
        $before = KataState::snapshot($this->list->fresh(), $this->pools());
        $this->score($pre[0], 'referee_score', '8', ['revision' => $revision, 'confirmed' => true])->assertStatus(409);
        $this->assertSame($before, KataState::snapshot($this->list->fresh(), $this->pools()));
    }

    public function test_activity_failure_rolls_back_score_final_deletion_and_medals(): void
    {
        [$pre] = $this->awarded();
        $before = KataState::snapshot($this->list->fresh(), $this->pools());
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $events = clone $dispatcher;
        $events->listen(QueryExecuted::class, function ($event) {
            if (str_starts_with($event->sql, 'insert into "activity_log"')) {
                throw new \RuntimeException('Test audit failure');
            }
        });
        $connection->setEventDispatcher($events);
        try {
            app(KataScoreService::class)->update($this->org, $pre[0], 'referee_score', '8', '9', true, $this->revision());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Test audit failure', $e->getMessage());
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, KataState::snapshot($this->list->fresh(), $this->pools()));
    }

    public function test_group_and_application_queries_do_not_grow_with_table_rows(): void
    {
        $members = collect(range(1, 3))->map(fn () => $this->user()->id)->all();
        $this->pool(['students' => $members, 'group_id' => 'first']);
        $countQueries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->base().'/kata/'.$this->list->id)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $initial = $countQueries();
        foreach (range(1, 10) as $i) {
            $this->pool(['students' => $members, 'group_id' => 'group-'.$i]);
        }
        $this->assertLessThanOrEqual($initial + 1, $countQueries());
    }

    public function test_final_video_limit_preserves_old_video_on_rejection_and_accepts_boundary(): void
    {
        [$pool, $category, $application] = $this->videoFixture();
        foreach (['ru', 'en'] as $locale) {
            $this->post($this->base().'/kata-pools/'.$pool->id.'/final-video', [
                'category_id' => $category->id,
                'video' => UploadedFile::fake()->create('large.mp4', 102401, 'video/mp4'),
            ], ['Accept' => 'application/json', 'Accept-Language' => $locale])
                ->assertUnprocessable()->assertJsonValidationErrors('video');
            $this->assertSame('online-kata-videos/old.mp4', $application->fresh()->online_kata_second_round_video_path);
            $this->assertSame(['online-kata-videos/old.mp4'], Storage::disk('protected')->allFiles());
        }
        $this->post($this->base().'/kata-pools/'.$pool->id.'/final-video', [
            'category_id' => $category->id,
            'video' => UploadedFile::fake()->create('limit.mp4', 102400, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotSame('online-kata-videos/old.mp4', $application->fresh()->online_kata_second_round_video_path);
    }

    private function videoFixture(): array
    {
        $p = $this->scored(8, ['round' => 'FINAL']);
        $category = EducationKlassCategory::create(['name' => 'Kata', 'price' => 0]);
        $application = StudentTournament::create(['tournament_id' => $this->tournament->id, 'list_tournament_id' => $this->list->id, 'student_id' => $p->student_id, 'online_kata_second_round_category_id' => $category->id, 'online_kata_second_round_video_path' => 'online-kata-videos/old.mp4']);
        Storage::disk('protected')->put('online-kata-videos/old.mp4', 'old');
        Storage::disk('public')->put('online-kata-videos/old.mp4', 'legacy');

        return [$p, $category, $application];
    }

    private function upload(KataPool $p, int $category)
    {
        return $this->post($this->base().'/kata-pools/'.$p->id.'/final-video', ['video' => UploadedFile::fake()->create('video.mp4', 10, 'video/mp4'), 'category_id' => $category], ['Accept' => 'application/json']);
    }

    public function test_video_replacement_is_private_removes_old_and_keeps_shared_files(): void
    {
        [$p, $category, $app] = $this->videoFixture();
        $this->upload($p, $category->id)->assertOk()->assertJsonMissingPath('video_path');
        $path = $app->refresh()->online_kata_second_round_video_path;
        Storage::disk('protected')->assertExists($path);
        (new DeleteUnusedKataVideo('online-kata-videos/old.mp4'))->handle(app(ProtectedMedia::class));
        Storage::disk('protected')->assertMissing('online-kata-videos/old.mp4');
        Storage::disk('public')->assertMissing('online-kata-videos/old.mp4');
        $this->assertDatabaseCount('jobs', 1);
        $other = StudentTournament::create(['tournament_id' => $this->tournament->id, 'list_tournament_id' => $this->list->id, 'student_id' => $this->user()->id, 'online_kata_first_round_video_path' => $path]);
        $this->upload($p, $category->id)->assertOk();
        (new DeleteUnusedKataVideo($path))->handle(app(ProtectedMedia::class));
        Storage::disk('protected')->assertExists($path);
        $other->delete();
        (new DeleteUnusedKataVideo($path))->handle(app(ProtectedMedia::class));
        Storage::disk('protected')->assertMissing($path);
        $this->get('/storage/'.$app->refresh()->online_kata_second_round_video_path)->assertNotFound();
    }

    public function test_video_database_failure_compensates_new_file_and_preserves_previous_file(): void
    {
        [$p, $category, $application] = $this->videoFixture();
        $dispatcher = StudentTournament::getEventDispatcher();
        StudentTournament::setEventDispatcher(clone $dispatcher);
        StudentTournament::saving(fn () => throw new \RuntimeException('Test database failure'));
        try {
            app(KataFinalVideoService::class)->replace($this->org, $p, $p->student_id, $category->id, UploadedFile::fake()->create('video.mp4', 10, 'video/mp4'));
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Test database failure', $e->getMessage());
        } finally {
            StudentTournament::setEventDispatcher($dispatcher);
        }
        $this->assertSame('online-kata-videos/old.mp4', $application->fresh()->online_kata_second_round_video_path);
        $this->assertSame(['online-kata-videos/old.mp4'], Storage::disk('protected')->allFiles());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_mobile_coach_replaces_only_their_final_video_in_an_active_assigned_tournament(): void
    {
        [$pool, $category, $application] = $this->videoFixture();
        $coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        User::whereKey($pool->student_id)->update(['coach_id' => $coach->id]);
        DB::table('tournament_treners')->insert(['tournament_id' => $this->tournament->id, 'trener_id' => $coach->id]);
        $this->acceptMobileAgreements($coach);
        MobileAccessToken::create(['user_id' => $coach->id, 'name' => 'test', 'token' => hash('sha256', 'kata-secret'), 'expires_at' => now()->addHour()]);
        $url = "/api/mobile/championships/{$this->champ->id}/tournaments/{$this->tournament->id}/kata-pools/{$pool->id}/final-video";
        $send = fn () => $this->withToken('kata-secret')->post($url, ['video' => UploadedFile::fake()->create('video.mp4', 10, 'video/mp4'), 'category_id' => $category->id], ['Accept' => 'application/json']);
        $send()->assertOk()->assertJsonPath('row.video_uploaded', true)->assertJsonPath('row.club', 'DOJO');
        $stored = $application->fresh()->online_kata_second_round_video_path;
        User::whereKey($pool->student_id)->update(['coach_id' => $this->user('Coach')->id]);
        $send()->assertForbidden();
        User::whereKey($pool->student_id)->update(['coach_id' => $coach->id]);
        $this->tournament->update(['date_finish' => now()->subDay()]);
        $send()->assertForbidden();
        $this->tournament->update(['date_finish' => now()->addDay()]);
        DB::table('tournament_treners')->where('trener_id', $coach->id)->delete();
        $send()->assertForbidden();
        $this->assertSame($stored, $application->fresh()->online_kata_second_round_video_path);
        Storage::disk('protected')->assertExists($stored);
    }

    public function test_video_cannot_target_another_student_in_the_same_list(): void
    {
        [$p, $category] = $this->videoFixture();
        $other = $this->user();
        StudentTournament::create(['tournament_id' => $this->tournament->id, 'list_tournament_id' => $this->list->id, 'student_id' => $other->id]);
        $this->post($this->base().'/kata-pools/'.$p->id.'/final-video', ['student_id' => $other->id, 'category_id' => $category->id, 'video' => UploadedFile::fake()->create('video.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(['online-kata-videos/old.mp4'], Storage::disk('protected')->allFiles());
    }

    public function test_score_invalidation_removes_medals_from_rating(): void
    {
        $scale = Scale::create(['name' => 'City', 'slug' => 'city', 'is_rating' => true]);
        $this->tournament->update(['scale_id' => $scale->id]);
        [$pre, $final] = $this->awarded();
        $coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        User::whereIn('id', $pre->pluck('student_id'))->update(['birthday' => now()->subYears(10)->toDateString(), 'weight' => 30, 'gender' => 'm', 'rang' => '5 кю', 'coach_id' => $coach->id]);
        $rank = fn () => (new RatingService)->resolve(['discipline' => 'kata', 'year' => (string) now()->year]);
        $this->assertGreaterThan(0, $rank()['summary']['athletes_count']);
        $this->score($final[0], 'referee_score', '', ['confirmed' => true])->assertOk();
        $this->assertSame(0, $rank()['summary']['athletes_count']);
    }
}
