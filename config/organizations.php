<?php

declare(strict_types=1);

return [

    'invitations' => [
        // Stamped into expires_at at issue, so a change applies only to new invitations.
        'expires_after_days' => (int) env('INVITATIONS_EXPIRE_AFTER_DAYS', 7),
    ],

];
