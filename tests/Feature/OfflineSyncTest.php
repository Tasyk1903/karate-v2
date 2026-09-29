<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Offline\OfflineCommands;
use App\Services\Tournaments\Kata\KataScores;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private ListTournament $list;

    private Pool $pool;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization');
        $champ = Championship::create(['name' => 'Offline test', 'organization_id' => $this->org->id, 'banner' => 'test.jpg']);
        $tournament = Tournament::create(['name' => 'Test', 'championship_id' => $champ->id, 'organization_id' => $this->org->id, 'tournament_type' => 1, 'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'date_commission' => now(), 'date' => now(), 'date_finish' => now()->addDay(), 'address' => 'Test', 'fight_for_third_place' => false]);
        $template = TemplateStudentList::create(['name' => 'Test list', 'list_type' => 'kumite', 'user_id' => $this->org->id]);
        $this->list = ListTournament::create(['tournament_id' => $tournament->id, 'template_student_list_id' => $template->id, 'finalists_count' => 4]);
        $this->pool = Pool::create(['tournament_id' => $tournament->id, 'list_id' => $this->list->id, 'round' => 1, 'position_in_round' => 1, 'type' => 'final', 'student_id' => $this->user()->id, 'opponent_id' => $this->user()->id]);
        $this->token = $this->login($this->org);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role = 'Student', array $extra = []): User
    {
        return User::create($extra + ['first_name' => 'First', 'last_name' => 'Last', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_external' => false, 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function login(User $user): string
    {
        return $this->postJson('/api/offline/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('token');
    }

    private function snapshot(): array
    {
        return $this->withToken($this->token)->getJson('/api/offline/lists/'.$this->list->id)->assertOk()->json();
    }

    private function batch(array $snapshot, ?array $commands = null): array
    {
        return ['id' => (string) Str::uuid(), 'engine' => $snapshot['engine'], 'base_revision' => $snapshot['revision'], 'grant' => $snapshot['grant'], 'commands' => $commands ?? [['action' => 'winner', 'target' => OfflineCommands::key($this->pool), 'winner_id' => $this->pool->student_id, 'student_wazari_count' => 2]]];
    }

    private function sync(array $batch)
    {
        return $this->withToken($this->token)->postJson('/api/offline/lists/'.$this->list->id.'/sync', $batch);
    }

    public function test_download_scope_and_no_private_fields(): void
    {
        $this->withToken($this->token)->getJson('/api/offline/tournaments')->assertOk()->assertJsonCount(1, 'data');
        $snapshot = $this->snapshot();
        foreach (['email', 'password', 'passport', 'video_path', 'birthday'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', json_encode($snapshot));
        }
        $secretary = $this->user('Secretary', ['organization_id' => $this->org->id]);
        $this->withToken($this->login($secretary))->getJson('/api/offline/lists/'.$this->list->id)->assertOk();
        $other = $this->user('Organization');
        $this->withToken($this->login($other))->getJson('/api/offline/lists/'.$this->list->id)->assertForbidden();
        $coach = $this->user('Coach');
        $this->postJson('/api/offline/login', ['email' => $coach->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_retry_is_idempotent_and_changed_reuse_rejected(): void
    {
        $batch = $this->batch($this->snapshot());
        $first = $this->sync($batch)->assertOk()->json();
        $count = DB::table('activity_log')->count();
        $this->assertSame($first, $this->sync($batch)->assertOk()->json());
        $this->assertSame($count, DB::table('activity_log')->count());
        $this->assertDatabaseCount('offline_mutations', 1);
        $batch['commands'][0]['winner_id'] = $this->pool->opponent_id;
        $this->sync($batch)->assertConflict();
    }

    public function test_conflict_and_invalid_batch_leave_everything_unchanged(): void
    {
        $snapshot = $this->snapshot();
        $batch = $this->batch($snapshot);
        $batch['commands'][] = ['action' => 'winner', 'target' => OfflineCommands::key($this->pool), 'winner_id' => 999999];
        $this->sync($batch)->assertUnprocessable();
        $this->assertNull($this->pool->fresh()->winner_id);
        $this->assertDatabaseCount('offline_mutations', 0);
        $this->pool->update(['tatami_and_fight_number' => 'A-1']);
        $this->sync($this->batch($snapshot))->assertConflict();
        $this->assertNull($this->pool->fresh()->winner_id);
    }

    public function test_clear_absences_and_late_replay_and_expiry(): void
    {
        $snapshot = $this->snapshot();
        $this->travel(2)->days();
        $result = $this->sync($this->batch($snapshot))->assertOk()->json();
        $this->sync($this->batch($result, [['action' => 'absences', 'target' => OfflineCommands::key($this->pool), 'absent_ids' => []]]))->assertOk();
        $this->assertNull($this->pool->fresh()->winner_id);
        $this->travel(6)->days();
        $this->assertArrayNotHasKey('grant', $this->snapshot());
        $this->sync($this->batch($snapshot))->assertForbidden();
    }

    public function test_revoked_token_and_changed_ownership_are_rejected(): void
    {
        $snapshot = $this->snapshot();
        $this->list->tournament->update(['organization_id' => $this->user('Organization')->id]);
        $this->sync($this->batch($snapshot))->assertForbidden();
        $this->withToken($this->token)->postJson('/api/offline/logout')->assertOk();
        $this->withToken($this->token)->getJson('/api/offline/me')->assertUnauthorized();
    }

    public function test_local_worker_matches_server_for_brackets_and_kata(): void
    {
        $snapshot = $this->snapshot();
        $batch = $this->batch($snapshot);
        $local = $this->worker($snapshot, $batch['commands']);
        $server = $this->sync($batch)->assertOk()->json();
        $this->assertEquals($server['pools'], $local['pools']);
        $this->list->tournament->update(['tournament_type' => 2, 'tournament_type_kata' => 2]);
        $pool = KataPool::create(['student_id' => $this->pool->student_id, 'tournament_id' => $this->list->tournament_id, 'list_id' => $this->list->id, 'round' => 'PRELIMINARY STAGE', 'participant_number' => 1]);
        $snapshot = $this->snapshot();
        $commands = array_map(fn ($field) => ['action' => 'score', 'target' => OfflineCommands::key($pool), 'field' => $field, 'value' => '7.5'], KataScores::FIELDS);
        $local = $this->worker($snapshot, $commands);
        $server = $this->sync($this->batch($snapshot, $commands))->assertOk()->json();
        $this->assertEquals($server['kata_pools'], $local['kata_pools']);
        $this->assertSame('22.5', $server['kata_pools'][0]['total_score']);
    }

    public function test_local_swap_and_dependent_final_and_third_place_match_server(): void
    {
        $this->list->tournament->update(['fight_for_third_place' => true]);
        $this->pool->update(['type' => '1/2']);
        $other = Pool::create(['tournament_id' => $this->list->tournament_id, 'list_id' => $this->list->id, 'round' => 1, 'position_in_round' => 2, 'type' => '1/2', 'student_id' => $this->user()->id, 'opponent_id' => $this->user()->id]);
        $final = Pool::create(['tournament_id' => $this->list->tournament_id, 'list_id' => $this->list->id, 'round' => 2, 'position_in_round' => 1, 'type' => 'final']);
        $third = Pool::create(['tournament_id' => $this->list->tournament_id, 'list_id' => $this->list->id, 'round' => 3, 'position_in_round' => 1, 'type' => '3rd']);
        $commands = [
            ['action' => 'swap', 'first' => $this->pool->student_id, 'second' => $this->pool->opponent_id],
            ['action' => 'winner', 'target' => OfflineCommands::key($this->pool), 'winner_id' => $this->pool->opponent_id],
            ['action' => 'winner', 'target' => OfflineCommands::key($other), 'winner_id' => $other->student_id],
            ['action' => 'winner', 'target' => OfflineCommands::key($final), 'winner_id' => $this->pool->opponent_id],
            ['action' => 'winner', 'target' => OfflineCommands::key($third), 'winner_id' => $this->pool->student_id],
            ['action' => 'winner', 'target' => OfflineCommands::key($this->pool), 'winner_id' => $this->pool->student_id],
        ];
        $snapshot = $this->snapshot();
        $local = $this->worker($snapshot, $commands);
        $server = $this->sync($this->batch($snapshot, $commands))->assertOk()->json();
        $this->assertEquals($server['pools'], $local['pools']);
        $this->assertNull($final->fresh()->winner_id);
        $this->assertNull($third->fresh()->winner_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'offline.synced', 'causer_id' => $this->org->id]);
    }

    public function test_group_kata_generation_semantic_ids_and_invalidation_match_local_worker(): void
    {
        $this->list->tournament->update(['tournament_type' => 2, 'tournament_type_kata' => 2]);
        $commands = [];
        $teams = [];
        foreach (range(1, 4) as $i) {
            $team = KataPool::create(['student_id' => null, 'students' => [$this->user()->id, $this->user()->id, $this->user()->id], 'group_id' => (string) Str::uuid(), 'tournament_id' => $this->list->tournament_id, 'list_id' => $this->list->id, 'round' => 'PRELIMINARY STAGE', 'participant_number' => $i]);
            $teams[] = $team;
            foreach (KataScores::FIELDS as $field) {
                $commands[] = ['action' => 'score', 'target' => OfflineCommands::key($team), 'field' => $field, 'value' => (string) (5 + $i)];
            }
        }
        $commands[] = ['action' => 'final', 'confirmed' => true];
        foreach ($teams as $i => $team) {
            foreach (KataScores::FIELDS as $field) {
                $commands[] = ['action' => 'score', 'target' => 'k:FINAL:group:'.$team->group_id, 'field' => $field, 'value' => (string) (6 + $i)];
            }
        }
        $commands[] = ['action' => 'results', 'confirmed' => true];
        $snapshot = $this->snapshot();
        $local = $this->worker($snapshot, $commands);
        // Unrelated rows consume global server IDs; semantic commands must still address the right team.
        $otherList = ListTournament::create(['tournament_id' => $this->list->tournament_id, 'template_student_list_id' => $this->list->template_student_list_id]);
        KataPool::create(['id' => 1000, 'tournament_id' => $this->list->tournament_id, 'list_id' => $otherList->id, 'round' => 'PRELIMINARY STAGE']);
        $server = $this->sync($this->batch($snapshot, $commands))->assertOk()->json();
        $normalize = fn ($rows) => collect($rows)->map(fn ($r) => Arr::except($r, 'id'))->sortBy(fn ($r) => $r['round'].$r['group_id'])->values()->all();
        $this->assertEquals($normalize($server['kata_pools']), $normalize($local['kata_pools']));
        $this->assertCount(1, collect($server['kata_pools'])->where('winner_1', true));
        $edit = [['action' => 'score', 'target' => OfflineCommands::key($teams[0]), 'field' => 'referee_score', 'value' => null, 'confirmed' => true]];
        $local = $this->worker($server, $edit);
        $server = $this->sync($this->batch($server, $edit))->assertOk()->json();
        $this->assertEquals($server['kata_pools'], $local['kata_pools']);
        $this->assertCount(0, collect($server['kata_pools'])->where('round', 'FINAL'));
        $this->assertNull($teams[0]->fresh()->total_score);
    }

    public function test_round_robin_rejects_foreign_or_duplicate_prizes_and_matches_worker(): void
    {
        $this->pool->update(['type' => 'Round Robin']);
        $snapshot = $this->snapshot();
        $bad = [['action' => 'podium', 'first' => $this->pool->student_id, 'second' => $this->pool->student_id]];
        $this->sync($this->batch($snapshot, $bad))->assertUnprocessable();
        $commands = [['action' => 'podium', 'first' => $this->pool->student_id, 'second' => $this->pool->opponent_id]];
        $local = $this->worker($snapshot, $commands);
        $server = $this->sync($this->batch($snapshot, $commands))->assertOk()->json();
        $this->assertEquals($server['pools'], $local['pools']);
    }

    private function worker(array $snapshot, array $commands): array
    {
        $worker = base_path('../offline/engine/worker.php');
        if (! is_file(dirname($worker).'/runtime/vendor/autoload.php')) {
            $this->markTestSkipped('Prepare desktop engine before parity test.');
        }
        $process = new Process([getenv('OFFLINE_PHP') ?: PHP_BINARY, '-n', '-d', 'display_errors=stderr', $worker]);
        $process->setInput(json_encode(['snapshot' => $snapshot, 'commands' => $commands, 'locale' => 'en']));
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $json, $process->getOutput());

        return $json['data'];
    }
}
