<!doctype html>
<html lang="{{ $language }}"><body>
<h1>{{ __('account.reset_title', [], $language) }}</h1>
<p>{{ __('account.reset_intro', ['minutes' => config('auth.passwords.users.expire')], $language) }}</p>
<p><a href="{{ $resetUrl }}">{{ __('account.reset_action', [], $language) }}</a></p>
<p>{{ __('account.reset_ignore', [], $language) }}</p>
</body></html>
