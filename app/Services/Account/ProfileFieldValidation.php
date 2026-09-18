<?php

namespace App\Services\Account;

final class ProfileFieldValidation
{
    public static function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value && $date->format('Y-m-d') >= '1900-01-01'
                && $date->format('Y-m-d') <= today()->toDateString()) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public static function rank(?string $value): bool
    {
        return (bool) preg_match('/^(?:(?:10|[0-9]) кю|(?:10|[1-9]) дан)$/u', $value ?? '');
    }
}
