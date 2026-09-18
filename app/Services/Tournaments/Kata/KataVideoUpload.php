<?php

namespace App\Services\Tournaments\Kata;

final class KataVideoUpload
{
    public const MAX_KILOBYTES = 102400;

    public const MAX_BYTES = self::MAX_KILOBYTES * 1024;

    public static function rules(): array
    {
        return ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.self::MAX_KILOBYTES];
    }
}
