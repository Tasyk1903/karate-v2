<?php

namespace App\Services\Offline;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\User;
use App\Services\FightSoonNotifier;
use App\Services\TatamiCurrentFightBroadcaster;
use App\Services\Tournaments\BracketState;
use App\Services\Tournaments\BracketSwapService;
use App\Services\Tournaments\BracketTopology;
use App\Services\Tournaments\Kata\KataScores;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Auth\RequestGuard;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;

/** CLI-only, isolated in-memory database. Never boots the web application or its .env. */
final class LocalRuntime
{
    public static function boot(string $root, string $locale): void
    {
        $app = new Application($root);
        $app->instance('config', new Repository([
            'app' => ['locale' => $locale === 'en' ? 'en' : 'ru', 'fallback_locale' => 'en', 'key' => 'offline-local-revision', 'timezone' => 'UTC'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]],
            'auth' => ['defaults' => ['guard' => 'offline'], 'guards' => ['offline' => ['driver' => 'offline']]],
        ]));
        Facade::setFacadeApplication($app);
        $app->instance('files', new Filesystem);
        $app->instance('request', Request::create('/offline', 'POST'));
        foreach ([EventServiceProvider::class, DatabaseServiceProvider::class, TranslationServiceProvider::class, ValidationServiceProvider::class, AuthServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->boot();
        $app['auth']->extend('offline', fn () => new RequestGuard(fn () => null, $app['request']));
        // Local replay has no push, email or broadcast side effects.
        $app->instance(FightSoonNotifier::class, new class
        {
            public function handlePoolChanged($pool): void {}
        });
        $app->instance(TatamiCurrentFightBroadcaster::class, new class
        {
            public function broadcastForPool($pool): void {}
        });
    }

    public static function apply(array $snapshot, array $commands): array
    {
        abort_unless(($snapshot['protocol'] ?? null) === OfflineSnapshot::PROTOCOL && hash_equals(OfflineSnapshot::engineVersion(), $snapshot['engine'] ?? ''), 409, __('offline.engine_changed'));
        abort_unless(($snapshot['expires_at'] ?? 0) > time(), 403, __('offline.grant_invalid'));
        self::schema();
        DB::table('roles')->insert(['id' => 1, 'name' => $snapshot['actor']['role']]);
        DB::table('users')->insert(['id' => $snapshot['actor']['id'], 'organization_id' => $snapshot['actor']['organization_id'], 'role_id' => 1]);
        foreach (['championships' => 'championship', 'tournaments' => 'tournament', 'list_tournaments' => 'list'] as $table => $key) {
            self::insert($table, $snapshot[$key]);
        }
        foreach (['pools', 'kata_pools'] as $table) {
            foreach ($snapshot[$table] as $row) {
                self::insert($table, $row);
            }
        }
        $actor = User::findOrFail($snapshot['actor']['id']);
        auth()->setUser($actor);
        $list = ListTournament::findOrFail($snapshot['list']['id']);
        DB::transaction(fn () => OfflineReplay::run($list->tournament_id, function () use ($actor, $list, $commands): void {
            foreach ($commands as $command) {
                app(OfflineCommands::class)->execute($actor, $list->fresh(), $command);
            }
        }));
        $snapshot['list']['finalists_count'] = $list->fresh()->finalists_count;
        $pools = Pool::orderBy('id')->get();
        $snapshot['pools'] = array_values(BracketState::snapshot($pools));
        $snapshot['can_swap'] = app(BracketSwapService::class)->available($pools);
        $snapshot['swap_participant_ids'] = collect((new BracketTopology($pools))->seeds())->pluck('id')->values()->all();
        $snapshot['kata_pools'] = OfflineSnapshot::kataRows($list->fresh(), KataPool::orderBy('id')->get());

        return $snapshot;
    }

    private static function insert(string $table, array $row): void
    {
        $row = array_intersect_key($row, array_flip(Schema::getColumnListing($table)));
        foreach ($row as &$value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }
        DB::table($table)->insert($row);
    }

    private static function schema(): void
    {
        $tables = [
            'roles' => ['name'],
            'users' => ['organization_id', 'role_id', 'deleted_at'],
            'championships' => ['name', 'organization_id', 'deleted_at'],
            'tournaments' => ['name', 'championship_id', 'organization_id', 'tournament_type', 'tournament_type_kata', 'fight_for_third_place', 'date_finish', 'deleted_at'],
            'list_tournaments' => ['tournament_id', 'template_student_list_id', 'finalists_count'],
            'pools' => ['tournament_id', 'list_id', 'round', 'position_in_round', 'type', 'student_id', 'opponent_id', 'winner_id', 'tatami_and_fight_number', 'absent_student', 'absent_opponent', ...BracketState::SCORES, ...BracketState::PLACES],
            'kata_pools' => ['student_id', 'tournament_id', 'list_id', 'group_id', 'students', 'round', 'participant_number', 'rank', 'winner_1', 'winner_2', 'winner_3', ...KataScores::FIELDS, ...KataScores::DERIVED],
            'activity_log' => ['log_name', 'event', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'target_user_id', 'properties'],
        ];
        foreach ($tables as $table => $fields) {
            Schema::create($table, function (Blueprint $schema) use ($fields, $table): void {
                $schema->id();
                foreach ($fields as $field) {
                    $integer = str_ends_with($field, '_id') || in_array($field, ['role_id', 'finalists_count', 'tournament_type', 'tournament_type_kata', 'fight_for_third_place', 'position_in_round', 'participant_number', 'rank', 'winner_1', 'winner_2', 'winner_3', 'absent_student', 'absent_opponent', ...BracketState::SCORES, ...BracketState::PLACES]) || ($table === 'pools' && $field === 'round');
                    ($integer && $field !== 'group_id' ? $schema->integer($field) : $schema->text($field))->nullable();
                }
                $schema->timestamps();
            });
        }
    }
}
