<?php

namespace App\Services\Students;

final class BeltPresentation
{
    public function forRank(string $rank): array
    {
        $rank = mb_strtolower(trim($rank));
        $labels = [0 => 'whiteBelt', 10 => 'orangeBelt', 9 => 'orangeBelt', 8 => 'blueBelt', 7 => 'blueBelt', 6 => 'yellowBelt', 5 => 'yellowBelt', 4 => 'greenBelt', 3 => 'greenBelt', 2 => 'brownBelt', 1 => 'brownBelt'];
        $colors = [0 => '#FFFFFF', 10 => '#FF7F00', 9 => '#FF7F00', 8 => '#0000FF', 7 => '#0000FF', 6 => '#FFD700', 5 => '#FFD700', 4 => '#00FF00', 3 => '#00FF00', 2 => '#8B4513', 1 => '#8B4513'];
        $stripeColors = [9 => '#0000FF', 7 => '#FFD700', 5 => '#00FF00', 3 => '#8B4513', 1 => '#FFD700'];
        $label = 'beltNotSet';
        $color = '#e5e7eb';
        $stripes = [];
        $progress = 0;
        if (preg_match('/^(\d+)\s*(кю|kyu|дан|dan)$/u', $rank, $match)) {
            $number = (int) $match[1];
            if (in_array($match[2], ['дан', 'dan'], true) && $number >= 1 && $number <= 10) {
                $label = 'blackBelt';
                $color = '#000000';
                $stripes = array_fill(0, $number, '#FFD700');
                $progress = 100;
            } elseif (in_array($match[2], ['кю', 'kyu'], true) && isset($colors[$number])) {
                $label = $labels[$number];
                $color = $colors[$number];
                $stripes = isset($stripeColors[$number]) ? [$stripeColors[$number]] : [];
                $progress = $number === 0 ? 0 : ($number === 1 ? 96 : (11 - $number) * 10);
            }
        }

        return ['label_key' => $label, 'color' => $color, 'accent' => $stripes[0] ?? $color, 'stripes' => $stripes, 'progress' => $progress];
    }
}
