<?php

declare(strict_types=1);

return [
    'plans' => [
        'free' => [
            'name' => 'Free',
            'prices' => [
                env('STRIPE_PRICE_FREE_MONTHLY', 'price_free_monthly') => [
                    'key' => 'free_monthly',
                    'interval' => 'month',
                    'currency' => 'usd',
                    'amount' => 0,
                    'allowances' => [
                        'projects' => 2,
                    ],
                ],
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'prices' => [
                env('STRIPE_PRICE_PRO_MONTHLY', 'price_pro_monthly') => [
                    'key' => 'pro_monthly',
                    'lookup_key' => 'pro_month',
                    'interval' => 'month',
                    'currency' => 'usd',
                    'amount' => 2000,
                    'allowances' => [
                        'projects' => 10,
                    ],
                ],
            ],
        ],
    ],
];
