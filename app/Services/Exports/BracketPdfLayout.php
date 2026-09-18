<?php

namespace App\Services\Exports;

use Illuminate\Support\Collection;

final class BracketPdfLayout
{
    public function layout(Collection $pools, float $width = 277, float $height = 165): array
    {
        $rounds = $pools->where('type', '!=', '3rd')->groupBy('round')->sortKeys()->map(fn ($round) => $round->sortBy(fn ($p) => (int) $p->position_in_round)->values())->values();
        $count = max(1, $rounds->count());
        $gap = 5;
        $column = min(82, ($width - ($count - 1) * $gap) / $count);
        $offset = ($width - ($column * $count + $gap * ($count - 1))) / 2;
        $firstCount = max(1, $rounds->first()?->count() ?? 1);
        $step = $height / $firstCount;
        $card = min(30, $step - 2);
        $matches = [];
        $lines = [];
        foreach ($rounds as $r => $round) {
            foreach ($round->values() as $i => $pool) {
                $center = $height * ($i + 0.5) / $round->count();
                $matches[] = ['pool' => $pool, 'x' => $offset + $r * ($column + $gap), 'y' => $center - $card / 2,
                    'width' => $column, 'height' => $card, 'stage' => $r === $count - 1 ? __('exports.final') : __('exports.round', ['size' => 2 ** ($count - $r - 1)])];
                if ($r < $count - 1) {
                    $nextCount = $rounds[$r + 1]->count();
                    $nextY = $height * (min($nextCount - 1, intdiv($i, 2)) + 0.5) / $nextCount;
                    $x = $offset + $r * ($column + $gap) + $column;
                    $lines[] = ['x' => $x, 'y' => $center, 'w' => $gap / 2, 'h' => 0.2];
                    $lines[] = ['x' => $x + $gap / 2, 'y' => min($center, $nextY), 'w' => 0.2, 'h' => max(0.2, abs($nextY - $center))];
                    $lines[] = ['x' => $x + $gap / 2, 'y' => $nextY, 'w' => $gap / 2, 'h' => 0.2];
                }
            }
        }
        if ($third = $pools->firstWhere('type', '3rd')) {
            $matches[] = ['pool' => $third, 'x' => $offset + max(0, $count - 2) * ($column + $gap), 'y' => $height / 2 - $card / 2,
                'width' => $column, 'height' => $card, 'stage' => __('exports.third')];
        }

        return compact('matches', 'lines', 'width', 'height');
    }
}
