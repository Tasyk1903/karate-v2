<?php

namespace App\Http\Controllers;

use App\Services\Education\EducationWorkPayments;
use App\Services\Tournaments\OnlineKataPaymentService;
use Illuminate\Http\Request;

class PaymentCallbackController extends Controller
{
    public function callback(Request $request, OnlineKataPaymentService $payments)
    {
        $data = $request->validate(['object.id' => ['required', 'string', 'max:100']]);
        // Notification contents never authorize enrollment; fetch the payment at YooKassa.
        if (! app(EducationWorkPayments::class)->confirm($data['object']['id'])) {
            $payments->confirmPayment($data['object']['id']);
        }

        return response()->json(['status' => 'ok']);
    }

    public function returnOnlineKata()
    {
        return redirect('/online-kata/payment/complete');
    }
}
