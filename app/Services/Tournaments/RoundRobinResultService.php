<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RoundRobinResultService
{
    public function save(Tournament $tournament, int $list, User $actor, ?string $version, array $data): string
    {
        $data = Validator::make($data, [
            'pool_ids' => ['required', 'array', 'min:1'], 'pool_ids.*' => ['required', 'integer', 'distinct'],
            'winner_id_1rd_robbin' => ['required', 'integer'], 'winner_id_2rd_robbin' => ['required', 'integer'],
            'winner_id_3rd_robbin' => ['nullable', 'integer'],
        ])->validate();

        return app(BracketMutation::class)->run($tournament->id, $list, $actor, $version, 'tournament.round_robin.winners_updated', [], function ($pools) use ($data) {
            $submitted = array_map('intval', $data['pool_ids'] ?? []);
            sort($submitted);
            $actual = $pools->pluck('id')->sort()->values()->all();
            if (! $actual || $submitted !== $actual || $pools->contains(fn ($pool) => $pool->type !== 'Round Robin')) {
                throw ValidationException::withMessages(['pool_ids' => __('fights.scope')]);
            }
            $participants = $pools->flatMap(fn ($pool) => [$pool->student_id, $pool->opponent_id])->filter()->unique()->map(fn ($id) => (int) $id)->all();
            $places = array_map(fn ($key) => empty($data[$key]) ? null : (int) $data[$key], BracketState::PLACES);
            $selected = array_values(array_filter($places));
            if (! $places[0] || ! $places[1] || count($selected) !== count(array_unique($selected)) || array_diff($selected, $participants)) {
                throw ValidationException::withMessages(['winners' => __('fights.prizes')]);
            }
            foreach ($pools as $pool) {
                foreach (BracketState::PLACES as $index => $field) {
                    $pool->$field = $places[$index];
                }
            }
        });
    }
}
