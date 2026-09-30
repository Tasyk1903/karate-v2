<?php

namespace Tests\Unit;

use App\Services\Students\BeltPresentation;
use PHPUnit\Framework\TestCase;

class BeltPresentationTest extends TestCase
{
    public function test_all_kyu_colors_and_stripes_match_legacy(): void
    {
        $colors = [0 => '#FFFFFF', 10 => '#FF7F00', 9 => '#FF7F00', 8 => '#0000FF', 7 => '#0000FF', 6 => '#FFD700', 5 => '#FFD700', 4 => '#00FF00', 3 => '#00FF00', 2 => '#8B4513', 1 => '#8B4513'];
        $stripes = [9 => ['#0000FF'], 7 => ['#FFD700'], 5 => ['#00FF00'], 3 => ['#8B4513'], 1 => ['#FFD700']];
        foreach ($colors as $rank => $color) {
            $belt = (new BeltPresentation)->forRank($rank.' кю');
            $this->assertSame($color, $belt['color']);
            $this->assertSame($stripes[$rank] ?? [], $belt['stripes']);
            $this->assertSame($belt, (new BeltPresentation)->forRank($rank.' kyu'));
        }
    }

    public function test_dan_has_one_gold_stripe_per_grade_and_unknown_rank_is_neutral(): void
    {
        foreach (range(1, 10) as $rank) {
            $belt = (new BeltPresentation)->forRank($rank.' дан');
            $this->assertSame('#000000', $belt['color']);
            $this->assertSame(array_fill(0, $rank, '#FFD700'), $belt['stripes']);
            $this->assertSame($belt, (new BeltPresentation)->forRank($rank.' DAN'));
        }
        foreach (['', '—', '11 кю', '0 дан', '11 dan'] as $rank) {
            $belt = (new BeltPresentation)->forRank($rank);
            $this->assertSame('beltNotSet', $belt['label_key']);
            $this->assertSame([], $belt['stripes']);
        }
    }
}
