<?php

namespace App\Services\Account;

final class MobileAppLinks
{
    public static function downloads(): array
    {
        $links = [];
        foreach (['ios', 'android'] as $platform) {
            $url = config('mobile_app.'.$platform.'_url');
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https') {
                $links[$platform] = $url;
            }
        }

        return $links;
    }
}
