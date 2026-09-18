<?php

return [
    'shop_id' => env('YOOKASSA_SHOP_ID', ''),
    'api_key' => env('YOOKASSA_API_KEY', ''),
    'online_kata_price' => env('YOOKASSA_ONLINE_KATA_PRICE', '1000.00'),
    'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1),
];
