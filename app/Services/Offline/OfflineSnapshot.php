<?php

namespace App\Services\Offline;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\User;
use App\Services\Tournaments\BracketParticipantLabels;
use App\Services\Tournaments\BracketState;
use App\Services\Tournaments\BracketSwapService;
use App\Services\Tournaments\BracketTopology;
use App\Services\Tournaments\Kata\KataScores;
use App\Services\Tournaments\Kata\KataState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OfflineSnapshot
{
    public const PROTOCOL = 1;

    public function capture(ListTournament $list, User $actor): array
    {
        $tournament = $list->tournament;
        $brackets = Pool::where('list_id', $list->id)->where('tournament_id', $tournament->id)->orderBy('id')->get();
        $kata = KataPool::where('list_id', $list->id)->where('tournament_id', $tournament->id)->orderBy('id')->get();
        $ids = $brackets->flatMap(fn ($p) => [$p->student_id, $p->opponent_id])
            ->merge($kata->flatMap(fn ($p) => [$p->student_id, ...($p->students ?? [])]))->filter()->unique();
        $students = User::whereIn('id', $ids)->select(['id', 'first_name', 'last_name', 'coach_id', 'region_id', 'trener_from_ankieta'])->with('coach:id,first_name,last_name,club,region_id')->orderBy('id')->get();
        $labels = new BracketParticipantLabels($tournament, $students);
        $pools = array_values(BracketState::snapshot($brackets));
        $kataRows = self::kataRows($list, $kata);
        $snapshot = [
            'protocol' => self::PROTOCOL, 'engine' => self::engineVersion(),
            'actor' => ['id' => $actor->id, 'organization_id' => $actor->organization_id, 'role' => $actor->hasProjectRole('Organization') ? 'Organization' : 'Secretary'],
            'championship' => $tournament->championship->only(['id', 'name', 'organization_id', 'deleted_at']),
            'tournament' => $tournament->only(['id', 'name', 'championship_id', 'organization_id', 'tournament_type', 'tournament_type_kata', 'fight_for_third_place', 'date_finish', 'deleted_at']),
            'list' => $list->only(['id', 'tournament_id', 'template_student_list_id', 'finalists_count']),
            'name' => $list->templateStudentList?->name ?? (string) $list->id,
            'kind' => (int) $tournament->tournament_type === 2 && (int) $tournament->tournament_type_kata === 2 ? 'kata' : 'bracket',
            'participants' => $students->map(fn ($student) => ['id' => $student->id, 'name' => $student->full_name, 'line' => $labels->line($student)])->values()->all(),
            'pools' => $pools, 'kata_pools' => $kataRows,
            'can_swap' => app(BracketSwapService::class)->available($brackets),
            'swap_participant_ids' => collect((new BracketTopology($brackets))->seeds())->pluck('id')->values()->all(),
            // Membership changes also invalidate a downloaded list, even before regeneration.
            'memberships' => DB::table('tournament_student_lists')->where('list_tournament_id', $list->id)->orderBy('id')->get(['id', 'student_id', 'group_id'])->map(fn ($r) => (array) $r)->all(),
        ];
        $snapshot = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true);
        $snapshot['revision'] = self::revision($snapshot);

        return $snapshot;
    }

    public static function revision(array $snapshot): string
    {
        unset($snapshot['revision'], $snapshot['grant'], $snapshot['expires_at'], $snapshot['actor']);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public static function kataRows(ListTournament $list, Collection $pools): array
    {
        $rows = array_values(KataState::snapshot($list, $pools)['pools']);
        foreach ($rows as &$row) {
            foreach ([...KataScores::FIELDS, ...KataScores::DERIVED] as $field) {
                $value = $row[$field];
                // MySQL decimals and SQLite floats must serialize identically; do not round invalid input.
                $row[$field] = $value === null ? null : (is_numeric($value) && abs((float) $value * 10 - round((float) $value * 10)) < 0.000001 ? number_format((float) $value, 1, '.', '') : (string) $value);
            }
            foreach (['id', 'student_id', 'tournament_id', 'list_id', 'participant_number', 'rank'] as $field) {
                if (is_numeric($row[$field] ?? null)) {
                    $row[$field] = (int) $row[$field];
                }
            }
        }

        return $rows;
    }

    public static function engineVersion(): string
    {
        $files = [...glob(app_path('Services/Tournaments/Kata/*.php')), ...array_map(fn ($name) => app_path('Services/Tournaments/'.$name.'.php'), ['BracketService', 'BracketState', 'BracketTopology', 'BracketMutation', 'FightResultService', 'BracketSwapService', 'RoundRobinResultService', 'TournamentLifecycle']), ...array_map(fn ($name) => app_path('Models/'.$name.'.php'), ['Pool', 'KataPool', 'ListTournament', 'Tournament', 'Championship', 'User']), __DIR__.'/OfflineCommands.php', __DIR__.'/OfflineSnapshot.php', __DIR__.'/OfflineReplay.php', __DIR__.'/LocalRuntime.php', base_path('composer.lock')];
        $hashes = [];
        foreach ($files as $file) {
            $hashes[basename(str_replace('\\', '/', $file))] = hash('sha256', str_replace("\r\n", "\n", file_get_contents($file)));
        }
        ksort($hashes);

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }
}
