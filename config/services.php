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

    /*
     * KPN SSO (Darwinbox) token check. The inbound `data` payload is
     * base64 -> XOR(xor_key) -> base64 -> JSON with { email, token }; the token
     * is then verified against `check_token_url`.
     */
    'sso' => [
        'check_token_url' => env('SSO_CHECK_TOKEN_URL', 'https://kpncorporation.darwinbox.com/checkToken'),
        'api_key' => env('SSO_API_KEY'),
        'authorization' => env('SSO_AUTHORIZATION'),
        'xor_key' => env('SSO_XOR_KEY', '666666'),
        'failure_redirect' => env('SSO_FAILURE_REDIRECT', 'https://kpncorporation.darwinbox.com/'),
    ],

    /*
     * Local/QA employee-impersonation login. Disabled unless a key is set.
     */
    'dev_login' => [
        'key' => env('DEV_LOGIN_KEY'),
    ],

];
