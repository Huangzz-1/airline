<?php

return [
    'mode' => env('PAYMENT_MODE', 'gateway'),
    'ca_bundle' => env('PAYMENTS_CA_BUNDLE', true),
    'simulator_enabled' => (bool) env('PAYMENT_SIMULATOR_ENABLED', false),
    'vnpay' => ['enabled' => (bool) env('VNPAY_ENABLED', false), 'tmn_code' => env('VNPAY_TMN_CODE'), 'hash_secret' => env('VNPAY_HASH_SECRET'), 'url' => env('VNPAY_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html')],
    'momo' => ['enabled' => (bool) env('MOMO_ENABLED', false), 'partner_code' => env('MOMO_PARTNER_CODE'), 'access_key' => env('MOMO_ACCESS_KEY'), 'secret_key' => env('MOMO_SECRET_KEY'), 'url' => env('MOMO_URL', 'https://test-payment.momo.vn')],
    'bank' => ['enabled' => (bool) env('BANK_TRANSFER_ENABLED', false), 'mode' => env('BANK_TRANSFER_MODE', 'live'), 'name' => env('BANK_NAME'), 'account_number' => env('BANK_ACCOUNT_NUMBER'), 'account_name' => env('BANK_ACCOUNT_NAME')],
];
