<?php

namespace App\Services\Tournaments\Kata;

use App\Models\KataPool;
use Illuminate\Validation\ValidationException;

final class KataScores
{
    public const FIELDS = ['referee_score', 'judge1_score', 'judge2_score', 'judge3_score', 'judge4_score'];

    public const DERIVED = ['total_score', 'min_score', 'max_score'];

    public static function normalize(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! preg_match('/^(?:[0-9]|10)(?:[.,][0-9])?$/D', trim((string) $value))) {
            throw ValidationException::withMessages(['value' => __('kata.score_range')]);
        }
        $number = (float) str_replace(',', '.', trim((string) $value));
        if ($number > 10) {
            throw ValidationException::withMessages(['value' => __('kata.score_range')]);
        }

        return number_format($number, 1, '.', '');
    }

    public static function complete(KataPool $pool): bool
    {
        foreach (self::FIELDS as $field) {
            try {
                if (self::normalize($pool->getAttributes()[$field] ?? null) === null) {
                    return false;
                }
            } catch (ValidationException) {
                return false;
            }
        }

        return true;
    }

    public static function calculate(KataPool $pool): void
    {
        $values = [];
        if (self::complete($pool)) {
            foreach (self::FIELDS as $field) {
                $values[] = (int) round((float) self::normalize($pool->getAttributes()[$field]) * 10);
            }
        }
        if (count($values) !== 5) {
            foreach (self::DERIVED as $field) {
                $pool->$field = null;
            }

            return;
        }
        sort($values);
        $pool->total_score = number_format(array_sum(array_slice($values, 1, 3)) / 10, 1, '.', '');
        $pool->min_score = number_format($values[0] / 10, 1, '.', '');
        $pool->max_score = number_format($values[4] / 10, 1, '.', '');
    }

    public static function compare(KataPool $a, KataPool $b): int
    {
        foreach (self::DERIVED as $field) {
            $left = (int) round((float) $a->$field * 10);
            $right = (int) round((float) $b->$field * 10);
            if ($left !== $right) {
                return $right <=> $left;
            }
        }

        return 0;
    }

    public static function identity(KataPool $pool): string
    {
        if ($pool->group_id) {
            return 'group:'.$pool->group_id;
        }
        if ($pool->student_id) {
            return 'student:'.$pool->student_id;
        }
        $ids = array_map('intval', $pool->students ?: []);
        sort($ids);

        return 'members:'.implode(',', $ids);
    }
}
