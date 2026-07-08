<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | The Filament navigation group the read-only observability resources are
    | grouped under, and an optional sort order for the group.
    |
    */

    'navigation' => [
        'group' => 'Swarm',
        'sort' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | These surfaces expose run data (display-decrypted) and are gated
    | deny-by-default: the resources authorize against the Gate ability named
    | here before rendering. Define that Gate in your application — absent a
    | Gate definition, access is denied. Set to null to defer entirely to
    | Filament's own resource/panel authorization instead.
    |
    */

    'authorization' => [
        'ability' => 'viewSwarmObservability',
    ],

];
