<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Apple's push service, signed with a .p8 key from the developer account.
    // The key is either a path to the file or its contents with \n escapes.
    'apns' => [
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'private_key' => env('APNS_PRIVATE_KEY'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'ao.facturac.app'),
        'allowed_bundle_ids' => ['ao.facturac.app', 'ao.facturac.app.flutter'],
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        'private_key' => env('FCM_PRIVATE_KEY'),
    ],

    /*
     * The iPhone app as Apple names it, team id then bundle id. Published in
     * apple-app-site-association so the app may use this site's passkeys.
     */
    'ios' => [
        'app_id' => env('IOS_APP_ID'),
    ],

    // Explicit opt-in: the installed app's signing certificate, not the upload key.
    // Comma-separated, colon-delimited SHA-256 fingerprints permit key rotation.
    'android' => [
        'passkey_package' => env('ANDROID_PASSKEY_PACKAGE'),
        'passkey_sha256_cert_fingerprints' => explode(',', (string) env('ANDROID_PASSKEY_SHA256_CERT_FINGERPRINTS', '')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
