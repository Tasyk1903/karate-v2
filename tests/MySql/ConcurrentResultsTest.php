<?php

namespace Tests\MySql;

use App\Models\Championship;
use App\Models\EducationKlassCategory;
use App\Models\EducationKlassVideo;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Education\MasterReviews;
use App\Services\Tournaments\BracketState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentResultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'mysql'
            || ! str_starts_with(config('database.connections.mysql.database'), 'kr_scope_test_')) {
            $this->markTestSkipped('Requires a dedicated kr_scope_test_* MySQL database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    private function race(array $a, array $b): array
    {
        $dir = sys_get_temp_dir().'/kr-race-'.Str::uuid();
        mkdir($dir, 0700);
        $a += ['marker' => $dir.'/a', 'hold' => true];
        $b += ['marker' => $dir.'/b', 'hold' => false];
        $processes = array_map(fn ($input) => new Process([PHP_BINARY, base_path('tests/support/concurrent-result.php'), json_encode($input)], base_path(), null, null, 20), [$a, $b]);
        $wait = function (string $path) use ($processes): void {
            $deadline = microtime(true) + 8;
            while (! file_exists($path)) {
                if (microtime(true) > $deadline) {
                    $this->fail('Barrier timed out: '.implode(' ', array_map(fn ($p) => $p->isStarted() ? $p->getErrorOutput().$p->getOutput() : 'not started', $processes)));
                }
                usleep(10000);
            }
        };
        try {
            $processes[0]->start();
            $wait($dir.'/a.locked');
            $processes[1]->start();
            $wait($dir.'/b.started');
            usleep(250000);
            $this->assertTrue($processes[1]->isRunning(), 'Second writer must wait for the tournament lock.');
            touch($dir.'/a.release');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }

            return array_map(fn ($p) => json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR), $processes);
        } finally {
            touch($dir.'/a.release');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_concurrent_scores_and_fight_results_use_real_mysql_locks(): void
    {
        foreach (['Organization', 'Secretary', 'Student', 'Judge', 'Master'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $user = fn ($role, $extra = []) => User::forceCreate($extra + ['email' => Str::uuid().'@example.test', 'password' => 'password', 'first_name' => 'Test', 'last_name' => 'Actor', 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
        $org = $user('Organization');
        $secretary = $user('Secretary', ['organization_id' => $org->id]);
        $judge1 = $user('Judge', ['organization_id' => $org->id, 'judge_position' => 'judge1_score']);
        $judge2 = $user('Judge', ['organization_id' => $org->id, 'judge_position' => 'judge2_score']);
        $students = [$user('Student'), $user('Student')];
        $champ = Championship::forceCreate(['name' => 'Concurrent test', 'banner' => 'test.jpg', 'organization_id' => $org->id]);
        $tournament = Tournament::forceCreate(['name' => 'Test', 'organization_id' => $org->id, 'championship_id' => $champ->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM,
            'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'address' => 'Test',
            'date' => now(), 'date_commission' => now(), 'date_finish' => now()->addDay()]);
        $template = TemplateStudentList::forceCreate(['name' => 'Test', 'user_id' => $org->id, 'list_type' => 'kata', 'kata_type' => 'personal']);
        $list = ListTournament::forceCreate(['tournament_id' => $tournament->id, 'template_student_list_id' => $template->id]);
        foreach ([false, true] as $sameColumn) {
            $pool = KataPool::forceCreate(['tournament_id' => $tournament->id, 'list_id' => $list->id, 'student_id' => $students[0]->id, 'round' => 'PRELIMINARY STAGE']);
            $base = ['kind' => 'kata', 'pool' => $pool->id];
            $result = $this->race($base + ['actor' => $org->id, 'field' => 'judge1_score', 'value' => 7.1], $base + ['actor' => $secretary->id, 'field' => $sameColumn ? 'judge1_score' : 'judge2_score', 'value' => 8.2]);
            $this->assertSame([['status' => 200], ['status' => $sameColumn ? 409 : 200]], $result);
            $pool->refresh();
            $this->assertSame(7.1, (float) $pool->judge1_score);
            $this->assertSame($sameColumn ? 0.0 : 8.2, (float) $pool->judge2_score);
        }
        $judgePool = KataPool::forceCreate(['tournament_id' => $tournament->id, 'list_id' => $list->id, 'student_id' => $students[0]->id, 'round' => 'PRELIMINARY STAGE']);
        $base = ['kind' => 'kata', 'pool' => $judgePool->id];
        $this->assertSame([['status' => 200], ['status' => 200]], $this->race(
            $base + ['actor' => $judge1->id, 'field' => 'judge1_score', 'value' => 7.1],
            $base + ['actor' => $judge2->id, 'field' => 'judge2_score', 'value' => 8.2]));
        $this->assertSame(7.1, (float) $judgePool->fresh()->judge1_score);
        $this->assertSame(8.2, (float) $judgePool->fresh()->judge2_score);
        $this->assertSame([['status' => 200], ['status' => 403]], $this->race(
            ['kind' => 'position', 'tournament' => $tournament->id, 'judge' => $judge2->id, 'actor' => $org->id],
            $base + ['actor' => $judge2->id, 'field' => 'judge2_score', 'value' => 9.0]));
        $master = $user('Master');
        $category = EducationKlassCategory::forceCreate(['name' => 'Test', 'price' => 100]);
        $work = EducationKlassVideo::forceCreate(['student_id' => $students[0]->id, 'reviewer_id' => $master->id,
            'education_klass_category_id' => $category->id, 'path' => 'video/test.mp4', 'is_payment' => true, 'is_review' => false]);
        $review = ['kind' => 'master', 'actor' => $master->id, 'work' => $work->id, 'revision' => app(MasterReviews::class)->revision($work->fresh())];
        $this->assertSame([['status' => 200], ['status' => 409]], $this->race($review + ['description' => 'First'], $review + ['description' => 'Second']));
        $this->assertSame('First', $work->fresh()->description);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'education.review.updated')->count());
        $tournament->forceFill(['tournament_type' => Tournament::KUMITE])->save();
        $fight = Pool::forceCreate(['tournament_id' => $tournament->id, 'list_id' => $list->id, 'round' => 1, 'position_in_round' => 1, 'type' => 'final', 'student_id' => $students[0]->id, 'opponent_id' => $students[1]->id]);
        $base = ['kind' => 'fight', 'pool' => $fight->id, 'revision' => BracketState::version(collect([$fight->fresh()]))];
        $this->assertSame([['status' => 200], ['status' => 409]], $this->race(
            $base + ['actor' => $org->id, 'winner' => $students[0]->id], $base + ['actor' => $secretary->id, 'winner' => $students[1]->id]));
        $this->assertSame($students[0]->id, (int) $fight->fresh()->winner_id);
        $this->assertSame(5, DB::table('activity_log')->where('event', 'tournament.kata.score_updated')->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'tournament.pool.winner_updated')->count());
    }
}
