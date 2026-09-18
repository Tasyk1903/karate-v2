<?php

use App\Models\KataPool;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Education\MasterReviews;
use App\Services\Tournaments\FightResultService;
use App\Services\Tournaments\Kata\KataScoreService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || ! str_starts_with(config('database.connections.mysql.database'), 'kr_scope_test_')) {
    exit(90);
}
Http::preventStrayRequests();
Notification::fake();
Mail::fake();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$marker = $input['marker'];
$waiting = false;
DB::listen(function ($query) use ($input, $marker, &$waiting) {
    $table = $input['kind'] === 'master' ? 'education_klass_videos' : 'tournaments';
    if (! $waiting && $input['hold'] && str_contains($query->sql, $table) && str_contains($query->sql, 'for update')) {
        $waiting = true;
        touch($marker.'.locked');
        $deadline = microtime(true) + 10;
        while (! file_exists($marker.'.release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Test lock barrier timed out');
            }
            usleep(10000);
        }
    }
});
touch($marker.'.started');
try {
    $actor = User::findOrFail($input['actor']);
    if ($input['kind'] === 'master') {
        $request = Request::create('/review', 'POST', [
            'revision' => $input['revision'], 'description' => $input['description'], 'point' => '8',
            'detail_point' => '9', 'recommendation' => 'Practice', 'is_review' => true,
        ]);
        app(MasterReviews::class)->update($actor, $input['work'], $request);
    } elseif ($input['kind'] === 'position') {
        DB::transaction(function () use ($input) {
            Tournament::lockForUpdate()->findOrFail($input['tournament']);
            User::whereKey($input['judge'])->update(['judge_position' => 'judge4_score']);
        });
    } elseif ($input['kind'] === 'kata') {
        app(KataScoreService::class)->update(
            $actor, KataPool::findOrFail($input['pool']), $input['field'],
            $input['value'], null, false, null);
    } else {
        app(FightResultService::class)->save(
            Pool::findOrFail($input['pool']), $actor, $input['revision'], $input['winner'], [], null);
    }
    echo json_encode(['status' => 200]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e), 'message' => $e->getMessage()]);
    exit(1);
}
