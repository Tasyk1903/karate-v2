<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MobileAgreements
{
    public function required(User $user): bool
    {
        return ! $user->success_politic || ! $user->data_processing ||
            DB::table('agreements')->whereIn('id', array_keys(Agreements::REQUIRED))->count() !== count(Agreements::REQUIRED) ||
            app(Agreements::class)->pending($user)->isNotEmpty();
    }
}
