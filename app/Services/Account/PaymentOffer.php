<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentOffer
{
    public function requireAccepted(User $user): void
    {
        $document = DB::table('agreements')->where('id', 1)->first();
        if (! $document || ! DB::table('agreement_acceptances')->where('user_id', $user->id)->where('agreement_id', 1)
            ->where('version', app(Agreements::class)->version($document))->exists()) {
            throw ValidationException::withMessages(['accepted' => __('education_work.offer_required')]);
        }
    }
}
