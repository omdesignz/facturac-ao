<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Impersonation
    |--------------------------------------------------------------------------
    |
    | Support staff can step into a customer's account to reproduce a problem.
    | Every session is recorded, capped in length, and shown to the person doing
    | it, so this is a troubleshooting tool rather than a back door.
    |
    */

    'enabled' => (bool) env('IMPERSONATION_ENABLED', true),

    /**
     * How long a single troubleshooting session may last before the server ends
     * it. Kept short on purpose: reading a customer's screen is not something
     * that should quietly stay open all afternoon.
     */
    'max_minutes' => (int) env('IMPERSONATION_MAX_MINUTES', 30),

    /** A reason has to actually say something, so it is worth reading later. */
    'reason_min_length' => (int) env('IMPERSONATION_REASON_MIN_LENGTH', 10),

    /**
     * Route names refused while impersonating. These either bind the customer
     * to something irreversible (a fiscal document is filed with the AGT and
     * cannot be withdrawn), move money, or change the credentials that protect
     * the account — none of which support should ever do as the customer.
     */
    'blocked_routes' => [
        // Irreversible: filed with the AGT the moment it succeeds.
        'invoices.issue',
        'invoices.send',
        'recurring.store',
        'recurring.update',
        'recurring.run',
        'transport-documents.issue',
        'transport-documents.cancel',
        'agt.series.store',
        'imports.commit',

        // Moves money, or the references that collect it.
        'billing.checkout',
        'billing.payments.resume',
        'billing.references.refresh',
        'billing.references.simulate',

        // The customer's credentials for filing with the tax authority.
        'agt.connection.update',
        'agt.connection-checks.store',
        'agt.series.sync',

        // Would hand over the account itself. The read-only ones matter as much
        // as the writes: a recovery code or a 2FA secret read once is enough.
        'user-password.update',
        'user-profile-information.update',
        'two-factor.enable',
        'two-factor.disable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'passkey.store',
        'passkey.destroy',
        'passkey.registration-options',

        // Destroys customer records.
        'customers.destroy',
        'catalogue.destroy',
        'imports.destroy',

        // Their preference to make, not support's.
        'settings.work-session.update',
    ],

    /** Session keys holding the live impersonation, kept in one place. */
    'session' => [
        'impersonator' => 'impersonator_id',
        'record' => 'impersonation_session_id',
        'work_session_started_at' => 'impersonator_work_session_started_at',
    ],

];
