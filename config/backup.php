<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where backups are written
    |--------------------------------------------------------------------------
    |
    | A filesystem disk name. The default keeps archives on the same machine,
    | which protects against a bad migration but not against losing the
    | machine — point this at an off-site disk in production.
    |
    */

    'disk' => env('BACKUP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Which connection to archive
    |--------------------------------------------------------------------------
    |
    | Null follows the application's default connection, which is what you
    | want almost always. Name one explicitly when the data worth keeping
    | lives somewhere other than where the app happens to point.
    |
    */

    'connection' => env('BACKUP_CONNECTION'),

    'directory' => env('BACKUP_DIRECTORY', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many archives to keep. Fiscal records have their own multi-year
    | retention in the database itself; this number is about how far back an
    | operational restore can reach, not about the legal obligation.
    |
    */

    'keep' => (int) env('BACKUP_KEEP', 14),

    /*
    |--------------------------------------------------------------------------
    | Stored files
    |--------------------------------------------------------------------------
    |
    | Directories under storage/app to include alongside the database. Exports
    | are excluded on purpose: they are derived from the database and would
    | double the size of every archive.
    |
    */

    'include' => [
        'private/imports',
        // Logos are what issued documents print; an old one is kept for as
        // long as any document still shows it.
        'private/logos',
    ],

];
