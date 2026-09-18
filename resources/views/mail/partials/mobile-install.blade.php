@foreach (\App\Services\Account\MobileAppLinks::downloads() as $platform => $link)
    <p><a href="{{ $link }}">{{ __('mobile_registration.download_'.$platform, [], $language) }}</a></p>
@endforeach
@if (count(\App\Services\Account\MobileAppLinks::downloads()) < 2)
    <p>{{ __('mobile_registration.install_contact', [], $language) }}</p>
@endif
