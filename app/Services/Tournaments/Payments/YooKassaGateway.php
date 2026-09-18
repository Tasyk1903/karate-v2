<?php

namespace App\Services\Tournaments\Payments;

use Illuminate\Validation\ValidationException;
use YooKassa\Client;
use YooKassa\Client\CurlClient;

class YooKassaGateway
{
    private function client(): Client
    {
        if (! config('yookassa.shop_id') || ! config('yookassa.api_key')) {
            throw ValidationException::withMessages(['payment' => __('online_kata.not_configured')]);
        }
        $transport = new CurlClient;
        $transport->setTimeout(20);
        $transport->setConnectionTimeout(5);
        $client = new Client($transport);
        $client->setMaxRequestAttempts(0);
        $client->setAuth(config('yookassa.shop_id'), config('yookassa.api_key'));

        return $client;
    }

    public function create(array $payload, string $key): array
    {
        return $this->normalize($this->client()->createPayment($payload, $key));
    }

    public function fetch(string $id): array
    {
        return $this->normalize($this->client()->getPaymentInfo($id));
    }

    private function normalize(object $payment): array
    {
        return json_decode(json_encode($payment, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
