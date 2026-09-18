<?php

namespace App\Services\Tournaments\Payments;

use App\Models\OnlineKataApplication;
use App\Models\User;
use Illuminate\Support\Str;

final class KataPaymentPayload
{
    public function make(OnlineKataApplication $application, User $student, User $payer): array
    {
        $amount = ['value' => number_format($application->amount_minor / 100, 2, '.', ''), 'currency' => $application->currency];
        $description = Str::limit("Онлайн-ката: {$student->last_name} {$student->first_name}", 128, '');

        return [
            'amount' => $amount, 'capture' => true, 'description' => $description,
            'confirmation' => ['type' => 'redirect', 'return_url' => url('/online-kata/payment/complete').'?application='.$application->id],
            'receipt' => ['customer' => ['email' => $payer->email], 'items' => [[
                'description' => $description, 'quantity' => '1.00', 'amount' => $amount,
                'vat_code' => (int) config('yookassa.vat_code', 1), 'payment_mode' => 'full_payment', 'payment_subject' => 'service',
            ]]],
            'metadata' => ['paymentType' => 'online_kata', 'applicationId' => $application->id],
        ];
    }
}
