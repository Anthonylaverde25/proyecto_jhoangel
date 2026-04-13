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

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'prompt_table_extraction' => env('GEMINI_PROMPT_TABLE_EXTRACTION'),
    ],

    'azure' => [
        'key' => env('AZURE_DOCUMENT_INTELLIGENCE_KEY'),
        'endpoint' => env('AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT'),
    ],

    'google' => [
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
        'location' => env('GOOGLE_CLOUD_LOCATION', 'us'),
        'processor_id' => env('GOOGLE_DOCUMENT_AI_PROCESSOR_ID'),
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    ],

    'ocr' => [
        'driver' => env('OCR_DRIVER', 'azure'),
    ],
];
