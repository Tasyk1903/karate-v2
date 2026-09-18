<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\Scale;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\RatingService;
use App\Services\Tournaments\BracketService;
use App\Services\Tournaments\BracketState;
use App\Services\Tournaments\BracketTopology;
use App\Services\Tournaments\PoolGenerationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FightWorkflowTest extends TestCase
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
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization');
        $this->champ = Championship::create(['name' => 'Championship', 'organization_id' => $this->org->id, 'banner' => 'test.jpg']);
        $this->tournament = Tournament::create(['name' => 'Tournament', 'championship_id' => $this->champ->id, 'organization_id' => $this->org->id, 'tournament_type' => 1,
            'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'date_commission' => now()->addDay(), 'date' => now(), 'date_finish' => now()->addDay(), 'address' => 'City', 'fight_for_third_place' => true]);
        $template = TemplateStudentList::create(['name' => 'Kumite', 'list_type' => 'kumite', 'user_id' => $this->org->id]);
        $this->list = ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $template->id]);
        $this->actingAs($this->org);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role = 'Student', array $extra = []): User
    {
        return User::create($extra + ['first_name' => 'First', 'last_name' => 'Last', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function base(): string
    {
        return '/api/panel/tournaments/'.$this->champ->id.'/items/'.$this->tournament->id;
    }

    private function pools()
    {
        return Pool::where('list_id', $this->list->id)->orderBy('id')->get();
    }

    private function revision(): string
    {
        return BracketState::version($this->pools());
    }

    private function postFight(string $path, array $data = [])
    {
        return $this->postJson($this->base().$path, $data + ['revision' => $this->revision()]);
    }

    private function pool(array $attributes = []): Pool
    {
        return Pool::create($attributes + ['tournament_id' => $this->tournament->id, 'list_id' => $this->list->id, 'round' => 1, 'position_in_round' => 1]);
    }

    private function duel(array $attributes = []): Pool
    {
        return $this->pool($attributes + ['student_id' => $this->user()->id, 'opponent_id' => $this->user()->id]);
    }

    private function win(Pool $pool, string $side = 'student'): void
    {
        $pool->refresh();
        $this->postFight('/pools/'.$pool->id.'/winner', ['winner_id' => $pool->{$side.'_id'}, $side.'_wazari_count' => 2, $side.'_ippon' => true])->assertOk();
    }

    private function tree(): array
    {
        $quarters = collect(range(1, 4))->map(fn ($position) => $this->duel(['position_in_round' => $position, 'type' => '1/4']));
        $semis = collect(range(1, 2))->map(fn ($position) => $this->pool(['round' => 2, 'position_in_round' => $position, 'type' => '1/2']));
        $final = $this->pool(['round' => 3, 'type' => 'final']);
        // Legacy third-place rows can share the final round and position.
        $third = $this->pool(['round' => 3, 'type' => '3rd']);

        return [$quarters, $semis, $final, $third];
    }

    private function completedTree(): array
    {
        [$q, $s, $f, $t] = $this->tree();
        foreach ($q as $p) {
            $this->win($p);
        }
        foreach ($s as $p) {
            $this->win($p);
        }
        $this->win($f);
        $this->win($t);

        return [$q, $s, $f, $t];
    }

    public function test_swap_in_one_fight_and_between_fights_is_atomic_and_logged(): void
    {
        $a = $this->duel();
        $b = $this->duel(['position_in_round' => 2]);
        $first = $a->student_id;
        $second = $a->opponent_id;
        $this->getJson($this->base().'/brackets/'.$this->list->id)->assertOk()->assertJsonPath('can_swap', true)->assertJsonPath('revision', $this->revision());
        $url = '/brackets/'.$this->list->id.'/swap';
        $this->postFight($url, ['participant_1' => $first, 'participant_2' => $second, 'pool_ids' => [$a->id]])->assertOk();
        $this->assertSame([$second, $first], [$a->refresh()->student_id, $a->opponent_id]);
        $other = $b->student_id;
        $this->postFight($url, ['participant_1' => $first, 'participant_2' => $other, 'pool_ids' => [$a->id, $b->id]])->assertOk();
        $this->assertSame([$other, $first], [$a->refresh()->opponent_id, $b->refresh()->student_id]);
        $log = json_decode(DB::table('activity_log')->where('event', 'tournament.bracket.participants_swapped')->orderByDesc('id')->value('properties'), true);
        $this->assertCount(2, $log['changed_pool_ids']);
        $this->assertSame($first, $log['old'][$a->id]['opponent_id']);
        $this->assertSame($other, $log['new'][$a->id]['opponent_id']);
    }

    public function test_swap_rejects_same_foreign_duplicate_and_started_draw(): void
    {
        $p = $this->duel();
        $url = '/brackets/'.$this->list->id.'/swap';
        $body = ['participant_1' => $p->student_id, 'participant_2' => $p->opponent_id, 'pool_ids' => [$p->id]];
        foreach ([['participant_2' => $p->student_id], ['participant_2' => $this->user()->id], ['pool_ids' => [9999]], ['pool_ids' => [$p->id, $p->id]]] as $bad) {
            $this->postFight($url, array_replace($body, $bad))->assertUnprocessable();
        }
        foreach (['absent_student' => true, 'student_wazari_count' => 1, 'winner_id' => $p->student_id] as $field => $value) {
            $p->update([$field => $value]);
            $this->postFight($url, $body)->assertUnprocessable();
            $p->update([$field => $field === 'winner_id' ? null : 0]);
        }
        $p->update(['type' => 'Round Robin']);
        $this->postFight($url, $body)->assertUnprocessable();
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_winner_membership_score_limits_and_losing_side_are_checked_on_server(): void
    {
        $p = $this->duel();
        $url = '/pools/'.$p->id.'/winner';
        foreach ([['winner_id' => $this->user()->id], ['student_wazari_count' => 20], ['student_wazari_count' => -1], ['student_wazari_count' => 1.5], ['opponent_wazari_count' => 1], ['opponent_ippon' => true]] as $bad) {
            $this->postFight($url, $bad + ['winner_id' => $p->student_id])->assertUnprocessable();
            $this->assertNull($p->refresh()->winner_id);
        }
        $this->win($p);
        $this->assertSame(2, $p->refresh()->student_wazari_count);
        $this->assertTrue((bool) $p->student_ippon);
        $this->postFight('/pools/'.$p->id.'/absences', ['absent_ids' => []])->assertOk();
        $this->assertNull($p->refresh()->winner_id);
        foreach (BracketState::SCORES as $field) {
            $this->assertEquals(0, $p->$field);
        }
    }

    public function test_early_winner_change_clears_all_downstream_results_and_third_but_not_other_branch(): void
    {
        [$q, $s, $f, $t] = $this->completedTree();
        $unchanged = $s[1]->fresh()->getAttributes();
        $this->win($q[0], 'opponent');
        $this->assertSame($q[0]->opponent_id, $s[0]->refresh()->student_id);
        $this->assertSame($unchanged, $s[1]->fresh()->getAttributes());
        foreach ([$s[0], $f, $t] as $pool) {
            $pool->refresh();
            $this->assertNull($pool->winner_id);
            foreach (BracketState::SCORES as $field) {
                $this->assertEquals(0, $pool->$field);
            }
            $this->assertFalse((bool) $pool->absent_student);
            $this->assertFalse((bool) $pool->absent_opponent);
        }
        $this->assertNull($f->student_id);
        $this->assertNotNull($f->opponent_id);
        $this->assertNull($t->student_id);
        $this->assertNotNull($t->opponent_id);
        $log = json_decode(DB::table('activity_log')->orderByDesc('id')->value('properties'), true);
        $this->assertCount(4, $log['changed_pool_ids']);
        $this->assertNotNull($log['old'][$f->id]['winner_id']);
        $this->assertNull($log['new'][$f->id]['winner_id']);
    }

    public function test_same_winner_score_correction_keeps_later_fights(): void
    {
        [$q, $s, $f, $t] = $this->completedTree();
        $before = BracketState::snapshot(collect([$s[0]->fresh(), $s[1]->fresh(), $f->fresh(), $t->fresh()]));
        $this->postFight('/pools/'.$q[0]->id.'/winner', ['winner_id' => $q[0]->student_id, 'student_wazari_count' => 1])->assertOk();
        $this->assertSame($before, BracketState::snapshot(collect([$s[0]->fresh(), $s[1]->fresh(), $f->fresh(), $t->fresh()])));
    }

    public function test_absences_and_their_cancellation_invalidate_descendants_and_exclude_absent_loser(): void
    {
        [$q, $s, $f, $t] = $this->completedTree();
        $semi = $s[0]->refresh();
        $this->postFight('/pools/'.$semi->id.'/absences', ['absent_ids' => [$semi->student_id]])->assertOk();
        $this->assertNull($semi->refresh()->winner_id);
        $this->assertSame($semi->opponent_id, $f->refresh()->student_id);
        $this->assertNull($t->refresh()->student_id);
        $this->assertNull($t->winner_id);
        $this->postFight('/pools/'.$semi->id.'/absences', ['absent_ids' => [$semi->student_id, $semi->opponent_id]])->assertOk();
        $this->assertNull($f->refresh()->student_id);
        $this->postFight('/pools/'.$semi->id.'/absences', ['absent_ids' => []])->assertOk();
        $this->assertFalse((bool) $semi->refresh()->absent_student);
        $this->assertNull($f->refresh()->winner_id);
        $this->postFight('/pools/'.$f->id.'/winner', ['winner_id' => $f->opponent_id])->assertUnprocessable();
        $this->postFight('/pools/'.$semi->id.'/absences', ['absent_ids' => [$this->user()->id]])->assertUnprocessable();
        $this->postFight('/pools/'.$semi->id.'/absences', ['absent_ids' => [$semi->student_id, $semi->student_id]])->assertUnprocessable();
    }

    public function test_both_absences_promote_resolved_sibling_but_not_a_pending_opponent(): void
    {
        [$q, $s, $f] = $this->tree();
        $this->win($q[0]);
        $this->assertNull($f->refresh()->student_id);
        $this->postFight('/pools/'.$s[0]->id.'/winner', ['winner_id' => $s[0]->fresh()->student_id])->assertUnprocessable();
        $this->postFight('/pools/'.$q[1]->id.'/absences', ['absent_ids' => [$q[1]->student_id, $q[1]->opponent_id]])->assertOk();
        $this->assertSame($q[0]->student_id, $f->refresh()->student_id);
        $this->assertNull($s[0]->refresh()->winner_id);
        $this->postFight('/pools/'.$q[1]->id.'/absences', ['absent_ids' => []])->assertOk();
        $this->assertNull($f->refresh()->student_id);
        $this->postFight('/pools/'.$q[0]->id.'/absences', ['absent_ids' => []])->assertOk();
        $this->postFight('/pools/'.$q[1]->id.'/absences', ['absent_ids' => [$q[1]->student_id, $q[1]->opponent_id]])->assertOk();
        $this->win($q[0]);
        $this->assertSame($q[0]->student_id, $f->refresh()->student_id);
    }

    public function test_final_and_third_single_double_and_cleared_absence(): void
    {
        [, , $f, $t] = $this->completedTree();
        foreach ([$f, $t] as $p) {
            $p->refresh();
            $this->postFight('/pools/'.$p->id.'/absences', ['absent_ids' => [$p->student_id]])->assertOk();
            $this->assertSame($p->opponent_id, $p->refresh()->winner_id);
            $this->postFight('/pools/'.$p->id.'/absences', ['absent_ids' => [$p->student_id, $p->opponent_id]])->assertOk();
            $this->assertNull($p->refresh()->winner_id);
            $this->postFight('/pools/'.$p->id.'/absences', ['absent_ids' => []])->assertOk();
            $this->assertNull($p->refresh()->winner_id);
            $this->assertFalse((bool) $p->absent_student);
        }
    }

    public function test_pending_feeder_invalidates_final_even_when_its_empty_slot_does_not_change(): void
    {
        $a = $this->duel(['type' => '1/2']);
        $b = $this->duel(['type' => '1/2', 'position_in_round' => 2]);
        $f = $this->pool(['round' => 2, 'type' => 'final']);
        $this->win($a);
        $this->postFight('/pools/'.$b->id.'/absences', ['absent_ids' => [$b->student_id, $b->opponent_id]])->assertOk();
        $this->win($f);
        $this->assertNull($f->refresh()->opponent_id);
        $this->postFight('/pools/'.$b->id.'/absences', ['absent_ids' => []])->assertOk();
        $this->assertNull($f->refresh()->winner_id);
        $this->assertSame($a->student_id, $f->student_id);
        $this->assertNull($f->opponent_id);
        $this->assertEquals(0, $f->student_wazari_count);
    }

    public function test_round_robin_podium_is_distinct_scoped_and_invalidated_by_fight_changes(): void
    {
        $ids = [$this->user()->id, $this->user()->id, $this->user()->id];
        $pools = collect([[0, 1], [0, 2], [1, 2]])->map(fn ($pair, $index) => $this->pool(['type' => 'Round Robin', 'position_in_round' => $index + 1, 'student_id' => $ids[$pair[0]], 'opponent_id' => $ids[$pair[1]]]));
        $url = '/brackets/'.$this->list->id.'/round-robin/winners';
        $body = ['pool_ids' => $pools->pluck('id')->all(), 'winner_id_1rd_robbin' => $ids[0], 'winner_id_2rd_robbin' => $ids[1], 'winner_id_3rd_robbin' => $ids[2]];
        foreach ([['winner_id_2rd_robbin' => $ids[0]], ['winner_id_3rd_robbin' => $this->user()->id], ['pool_ids' => [$pools[0]->id]], ['pool_ids' => [9999]], ['winner_id_1rd_robbin' => $ids[0].'bad']] as $bad) {
            $this->postFight($url, array_replace($body, $bad))->assertUnprocessable();
        }
        $this->postFight($url, $body)->assertOk();
        foreach ($this->pools() as $p) {
            foreach (BracketState::PLACES as $index => $field) {
                $this->assertSame($ids[$index], $p->$field);
            }
        }
        $this->win($pools[0]);
        foreach ($this->pools() as $p) {
            foreach (BracketState::PLACES as $field) {
                $this->assertNull($p->$field);
            }
        }
        $this->postFight($url, array_replace($body, ['winner_id_3rd_robbin' => null]))->assertOk();
        $this->postFight('/pools/'.$pools[0]->id.'/absences', ['absent_ids' => [$ids[0]]])->assertOk();
        foreach ($this->pools() as $p) {
            foreach (BracketState::PLACES as $field) {
                $this->assertNull($p->$field);
            }
        }
        $pools[0]->update(['type' => 'final']);
        $this->postFight($url, $body)->assertUnprocessable();
    }

    public function test_revision_rejects_stale_writer_without_overwriting_anything(): void
    {
        $p = $this->duel();
        $revision = $this->revision();
        $this->win($p);
        $after = BracketState::snapshot($this->pools());
        $this->postFight('/pools/'.$p->id.'/winner', ['winner_id' => $p->opponent_id, 'revision' => $revision])->assertConflict();
        $this->postJson($this->base().'/pools/'.$p->id.'/winner', ['winner_id' => $p->opponent_id])->assertUnprocessable();
        $this->assertSame($after, BracketState::snapshot($this->pools()));
        $this->postFight('/pools/'.$p->id.'/tatami', ['value' => 'A-5'])->assertOk()->assertJsonPath('revision', fn ($v) => $v !== $revision);
        $this->assertSame('A-5', $p->refresh()->tatami_and_fight_number);
    }

    public function test_permissions_scope_and_end_date_apply_to_every_mutation(): void
    {
        $p = $this->duel();
        foreach ([$this->user('Organization'), $this->user('Coach', ['organization_id' => $this->org->id])] as $user) {
            $this->actingAs($user);
            $this->postFight('/pools/'.$p->id.'/winner', ['winner_id' => $p->student_id])->assertForbidden();
            $this->postFight('/pools/'.$p->id.'/absences', ['absent_ids' => []])->assertForbidden();
            $this->postFight('/brackets/'.$this->list->id.'/swap', ['participant_1' => $p->student_id, 'participant_2' => $p->opponent_id, 'pool_ids' => [$p->id]])->assertForbidden();
        }
        $this->actingAs($this->user('Secretary', ['organization_id' => $this->org->id]));
        $this->win($p);
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.pool.winner_updated', 'causer_id' => auth()->id()]);
        $this->tournament->update(['date_finish' => now()->subDay()]);
        $this->postFight('/pools/'.$p->id.'/winner', ['winner_id' => $p->opponent_id])->assertForbidden();
    }

    public function test_downstream_save_failure_rolls_back_result_and_activity(): void
    {
        [$q] = $this->completedTree();
        $before = BracketState::snapshot($this->pools());
        $logs = DB::table('activity_log')->count();
        $dispatcher = Pool::getEventDispatcher();
        Pool::setEventDispatcher(clone $dispatcher);
        Pool::saving(function (Pool $p) {
            if ($p->type === 'final') {
                throw new \RuntimeException('Simulated write failure');
            }
        });
        try {
            app(BracketService::class)->setWinner($q[0], $q[0]->opponent_id, [], $this->revision());
            $this->fail('Expected write failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated write failure', $error->getMessage());
        } finally {
            Pool::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, BracketState::snapshot($this->pools()));
        $this->assertSame($logs, DB::table('activity_log')->count());
    }

    public function test_activity_failure_rolls_back_all_fights_including_new_third_place(): void
    {
        $p = $this->duel(['type' => '1/2']);
        $this->pool(['round' => 2, 'type' => 'final']);
        $before = BracketState::snapshot($this->pools());
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $isolated = clone $dispatcher;
        $isolated->listen(QueryExecuted::class, function ($event) {
            if (str_starts_with($event->sql, 'insert into "activity_log"')) {
                throw new \RuntimeException('Simulated log failure');
            }
        });
        $connection->setEventDispatcher($isolated);
        try {
            app(BracketService::class)->setWinner($p, $p->student_id, [], $this->revision());
            $this->fail('Expected log failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated log failure', $error->getMessage());
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, BracketState::snapshot($this->pools()));
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_rating_drops_invalidated_final_and_semifinal_wins_and_scores(): void
    {
        $scale = Scale::create(['name' => 'City', 'slug' => 'city', 'is_rating' => true]);
        $this->tournament->update(['scale_id' => $scale->id]);
        [$q, , $final] = $this->completedTree();
        $ids = $q->flatMap(fn ($p) => [$p->student_id, $p->opponent_id])->all();
        $coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        User::whereIn('id', $ids)->update(['birthday' => now()->subYears(10)->toDateString(), 'weight' => 30, 'gender' => 'm', 'rang' => '5 кю', 'coach_id' => $coach->id]);
        $ranking = fn () => (new RatingService)->resolve(['year' => (string) now()->year, 'discipline' => 'kumite'])['groups']->flatMap(fn ($g) => $g['items']);
        $oldChampion = $final->fresh()->winner_id;
        $this->assertGreaterThan(0, $ranking()->firstWhere('student_id', $oldChampion)['rating_points']);
        $this->win($q[0], 'opponent');
        $this->assertNull($ranking()->firstWhere('student_id', $oldChampion));
    }

    public function test_actual_generated_draws_preserve_seeded_byes_and_accept_results_through_final(): void
    {
        foreach (array_merge([2], range(4, 32)) as $size) {
            $this->list->students()->detach();
            $this->pools()->each->delete();
            $ids = collect(range(1, $size))->map(fn () => $this->user()->id)->all();
            $this->list->students()->attach($ids);
            app(PoolGenerationService::class)->generate($this->tournament->id, $this->list->id);
            $seeds = collect((new BracketTopology($this->pools()))->seeds())->pluck('id')->sort()->values()->all();
            $sorted = $ids;
            sort($sorted);
            $this->assertSame($sorted, $seeds, 'Generated seeds: '.$size);
            $this->postFight('/brackets/'.$this->list->id.'/swap', ['participant_1' => $ids[0], 'participant_2' => $ids[$size - 1], 'pool_ids' => $this->pools()->pluck('id')->all()])->assertOk();
            foreach ($this->pools()->whereNotIn('type', ['3rd', 'Round Robin'])->sortBy(fn ($p) => [(int) $p->round, (int) $p->position_in_round]) as $p) {
                $p->refresh();
                if ($p->student_id && $p->opponent_id) {
                    $this->win($p);
                }
            }
            $final = $this->pools()->firstWhere('type', 'final');
            $this->assertNotNull($final?->winner_id, 'Final winner: '.$size);
        }
    }
}
