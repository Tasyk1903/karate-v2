<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class FeedText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = mb_strtolower((string) $value);
        foreach (config('mat_phrases', []) as $word) {
            if ($word !== '' && str_contains($text, mb_strtolower($word))) {
                $fail('feed.moderation')->translate();

                return;
            }
        }
    }
}
