<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    | How the Livewire surfaces learn that server state changed. `polling` is
    | the default because it needs no infrastructure: every host that installs
    | this package gets live-feeling surfaces immediately. `broadcast` is the
    | opt-in upgrade for hosts running Reverb/Pusher; components then listen
    | instead of polling. (Core design §7/§8: polling default, broadcast opt-in.)
    */
    'transport' => env('VERDICT_CONSOLE_LIVEWIRE_TRANSPORT', 'polling'),

    /*
    |--------------------------------------------------------------------------
    | Polling interval
    |--------------------------------------------------------------------------
    | Seconds between wire:poll refreshes while the polling transport is
    | active. The stale-actionable window for an expired approval is bounded by
    | this interval (core design: poll-consistency).
    */
    'polling' => [
        'interval_seconds' => 5,
    ],

];
