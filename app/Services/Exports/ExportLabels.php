<?php

namespace App\Services\Exports;

use Carbon\Carbon;

final class ExportLabels
{
    public static function rank(?string $rank): string
    {
        return preg_replace_callback('/(?:кю|kyu|дан|dan)/ui', fn ($m) => preg_match('/дан|dan/ui', $m[0]) ? __('exports.dan') : __('exports.kyu'), $rank ?? '');
    }

    public static function age(mixed $birthday): ?int
    {
        return $birthday ? Carbon::parse($birthday)->age : null;
    }
}
