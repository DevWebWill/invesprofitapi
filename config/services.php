<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'nominatim' => [
        'base_url' => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'InvesProfit/1.0 (https://investprofit.local; dev@investprofit.app)'),
        'referer' => env('NOMINATIM_REFERER', 'https://investprofit.local'),
    ],

    'cassandra_ai' => [
        'base_url' => env('CASSANDRA_AI_BASE_URL', 'https://api.cassandra.ai'),
        'token' => env('CASSANDRA_AI_TOKEN'),
        'timeout' => (float) env('CASSANDRA_AI_TIMEOUT', 30),
        'connect_timeout' => (float) env('CASSANDRA_AI_CONNECT_TIMEOUT', 10),
        'retry_times' => (int) env('CASSANDRA_AI_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('CASSANDRA_AI_RETRY_SLEEP_MS', 250),
    ],

];
