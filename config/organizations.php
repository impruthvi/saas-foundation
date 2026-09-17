<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | How long an invitation stays open. The demo seeder and a real deployment
    | want different answers to this, which is why it is configuration and not a
    | constant: seven days is a sensible default for a human checking email, and
    | far too long for a fixture.
    |
    | Expiry is derived from the stamped `expires_at`, so changing this affects
    | invitations issued from now on and leaves outstanding ones alone.
    |
    */

    'invitations' => [
        'expires_after_days' => (int) env('INVITATIONS_EXPIRE_AFTER_DAYS', 7),
    ],

];
