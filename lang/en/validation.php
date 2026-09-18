<?php

return array_replace_recursive(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'), [
    'attributes' => ['name' => 'name', 'first_name' => 'first name', 'last_name' => 'last name', 'email' => 'email', 'password' => 'password', 'password_confirmation' => 'password confirmation', 'organization_code' => 'organization code', 'coach_code' => 'coach code', 'existing_account' => 'existing account', 'challenge_token' => 'registration request', 'code' => 'email code', 'avatar' => 'avatar', 'version' => 'document version', 'accepted' => 'consent', 'token' => 'token'],
    'custom' => ['video' => [
        'max' => 'Video size exceeded. The maximum size is 100 MB.',
        'uploaded' => 'Video upload failed. The maximum size is 100 MB.',
    ]],
]);
