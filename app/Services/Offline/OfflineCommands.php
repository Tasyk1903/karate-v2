<?php

namespace App\Services\Offline;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\User;
use App\Services\Tournaments\BracketService;
use App\Services\Tournaments\BracketState;
use App\Services\Tournaments\Kata\KataFinalService;
use App\Services\Tournaments\Kata\KataResultService;
use App\Services\Tournaments\Kata\KataScores;
use App\Services\Tournaments\Kata\KataScoreService;
use App\Services\Tournaments\Kata\KataState;
use Illuminate\Support\Facades\Validator;

/** Identical dispatcher on server and desktop. The client never uploads result rows. */
final class OfflineCommands
{
    public static function key(Pool|KataPool $pool): string
    {
        return $pool instanceof KataPool ? 'k:'.$pool->round.':'.KataScores::identity($pool)
            : 'b:'.$pool->round.':'.$pool->position_in_round.':'.$pool->type;
    }

    public function execute(User $actor, ListTournament $list, array $command): void
    {
        $data = Validator::make($command, [
            'action' => ['required', 'in:winner,absences,tatami,swap,podium,score,finalists,final,results'],
            'target' => ['nullable', 'string', 'max:255'], 'confirmed' => ['sometimes', 'boolean'],
            'winner_id' => ['required_if:action,winner', 'integer'],
            'student_wazari_count' => ['sometimes', 'integer', 'between:0,2'], 'opponent_wazari_count' => ['sometimes', 'integer', 'between:0,2'],
            'student_ippon' => ['sometimes', 'boolean'], 'opponent_ippon' => ['sometimes', 'boolean'],
            'absent_ids' => ['present_if:action,absences', 'array', 'max:2'], 'absent_ids.*' => ['integer', 'distinct'],
            'value' => ['nullable'], 'field' => ['required_if:action,score', 'in:'.implode(',', KataScores::FIELDS)],
            'count' => ['required_if:action,finalists', 'integer', 'between:4,8'],
            'first' => ['required_if:action,swap,podium', 'integer'], 'second' => ['required_if:action,swap,podium', 'integer'], 'third' => ['nullable', 'integer'],
        ])->validate();
        $tournament = $list->tournament;
        $action = $data['action'];
        $kata = in_array($action, ['score', 'finalists', 'final', 'results']);
        $pools = ($kata ? KataPool::query() : Pool::query())->where('tournament_id', $tournament->id)->where('list_id', $list->id)->orderBy('id')->get();
        abort_if($pools->isEmpty(), 422, __('offline.not_prepared'));
        $target = null;
        if (in_array($action, ['winner', 'absences', 'tatami', 'score'])) {
            $matches = $pools->filter(fn ($pool) => self::key($pool) === ($data['target'] ?? ''));
            abort_unless($matches->count() === 1, 409, __('offline.target_changed'));
            $target = $matches->first();
        }
        $revision = $kata ? KataState::revision($list, $pools) : BracketState::version($pools);
        $brackets = app(BracketService::class);
        $confirmed = (bool) ($data['confirmed'] ?? false);
        switch ($action) {
            case 'winner': $brackets->setWinner($target, $data['winner_id'], $data, $revision);
                break;
            case 'absences': $brackets->setAbsences($target, $data['absent_ids'], $revision);
                break;
            case 'tatami':
                Validator::make($data, ['value' => ['nullable', 'string', 'max:255']])->validate();
                $brackets->updateTatami($target, $data['value'] ?? null, $revision);
                break;
            case 'swap': $brackets->swapParticipants($tournament, $list->id, $data['first'], $data['second'], $pools->pluck('id')->all(), $revision);
                break;
            case 'podium': $brackets->setRoundRobinWinners($tournament, $list->id, ['pool_ids' => $pools->pluck('id')->all(), 'winner_id_1rd_robbin' => $data['first'], 'winner_id_2rd_robbin' => $data['second'], 'winner_id_3rd_robbin' => $data['third'] ?? null], $revision);
                break;
            case 'score': app(KataScoreService::class)->update($actor, $target, $data['field'], $data['value'] ?? null, $target->getRawOriginal($data['field']), $confirmed, $revision);
                break;
            case 'finalists': app(KataFinalService::class)->count($actor, $tournament, $list->id, $data['count'], $confirmed, $revision);
                break;
            case 'final': app(KataFinalService::class)->generate($actor, $tournament, $list->id, $confirmed, $revision);
                break;
            case 'results': app(KataResultService::class)->generate($actor, $tournament, $list->id, $confirmed, $revision);
                break;
        }
    }
}
