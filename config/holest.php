<?php

return [


    // Logging configuration
    'log_enabled' => env('HOLESTPAY_LOG_ENABLED', true),
    'log_debug' => env('HOLESTPAY_LOG_DEBUG', false),
    'log_provider_class' => env('HOLESTPAY_LOG_PROVIDER_CLASS', 'FileSystemLogProvider'),
    'log_provider_class_namespace' => env('HOLESTPAY_LOG_PROVIDER_CLASS_NAMESPACE', '\holestpay'),
    'log_provider_folder' => env('HOLESTPAY_LOG_PROVIDER_FOLDER', '/home/www_private/holestpay_logs'),
    'log_expiration_days' => env('HOLESTPAY_LOG_EXPIRATION_DAYS', 7),

    // Exchange rate configuration
    'exchange_rate_source' => env('HOLESTPAY_EXCHANGE_RATE_SOURCE', 'https://pay.holest.com/clientpay/exchangerate?from={FROM}&to={TO}'),
    'exchange_rate_cache_h' => env('HOLESTPAY_EXCHANGE_RATE_CACHE_H', 4),
];